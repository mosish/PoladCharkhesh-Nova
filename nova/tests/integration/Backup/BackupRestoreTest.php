<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Backup;

use Nova\Backup\BackupConfig;
use Nova\Backup\BackupError;
use Nova\Backup\BackupManifest;
use Nova\Backup\BackupScheduler;
use Nova\Backup\DatabaseDump;
use Nova\Core\Sql;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Backup creation and restore, proven against disposable databases and temporary folders (05 §18: "restore must be
 * proven"). Restore targets are empty nova_rt_* databases created by the operator (NOVA_DB_RESTORE_A/B/C).
 */
final class BackupRestoreTest extends TestCase
{
    private BackupFixture $b;
    private string $errorLog = '';

    protected function setUp(): void
    {
        $this->b = new BackupFixture();
        $this->errorLog = (string) ini_set('error_log', $this->b->tmp . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
        $this->b->dispose();
    }

    /**
     * Every backed-up table of a database. The audit trail is left out of live-to-restored comparisons: a backup
     * writes its own audit row after the snapshot, so the live table legitimately holds rows the dump does not;
     * the restored audit table is proven against the manifest instead (`verifyTarget`).
     *
     * @param list<string> $except
     *
     * @return array<string, array{rows: int, checksum: string}>
     */
    private static function fingerprints(PDO $pdo, array $except = ['audit_event']): array
    {
        $out = [];
        foreach (DatabaseDump::tableNames($pdo) as $t) {
            if (!\in_array($t, $except, true)) {
                $out[$t] = DatabaseDump::fingerprint($pdo, $t);
            }
        }

        return $out;
    }

    // ---- creation

    public function testABackupSetIsCompleteVerifiedAndPrivate(): void
    {
        $seed = $this->b->seed();
        $this->b->addRelease('R-20261001-000000-aaaa', 'superseded');
        $this->b->addRelease('R-20261002-000000-bbbb', 'live');
        $run = $this->b->backups->run('manual');

        self::assertMatchesRegularExpression('/^B-\d{8}-\d{6}-[0-9a-f]{4}$/', $run['id']);
        self::assertSame([], $this->b->backups->verify($run['id']));
        $m = BackupManifest::read($this->b->paths, $run['id']);
        self::assertSame(['manual', 'test'], [$m['trigger'], $m['app_version']]);
        self::assertSame($m['schema_version'], Sql::int($this->b->f->pdo, 'SELECT MAX(version) FROM schema_migrations'));
        self::assertSame(self::fingerprints($this->b->f->pdo), array_diff_key($m['db']['tables'], ['audit_event' => 1]), 'the manifest holds the source fingerprint of every table');
        self::assertArrayNotHasKey('login_throttle', $m['db']['tables']);
        self::assertArrayNotHasKey('inquiry_attempt', $m['db']['tables']);
        self::assertSame(['inquiry_attempt', 'login_throttle'], $m['excluded'], 'structure kept, rows not backed up');
        self::assertSame(1, $m['db']['tables']['inquiry']['rows']);

        // The files the database records: the image, its 3 WebP copies, the PDF.
        self::assertCount(5, $m['media']['files']);
        self::assertSame([], $m['media']['missing']);
        self::assertSame(['R-20261001-000000-aaaa', 'R-20261002-000000-bbbb'], array_column($m['releases'], 'id'));
        self::assertSame($m['releases'][1]['manifest_sha256'], hash_file('sha256', $this->b->tmp . '/site/releases/R-20261002-000000-bbbb/manifest.json'));

        foreach ([$this->b->paths->dump($run['id']), $this->b->paths->manifest($run['id'])] as $file) {
            self::assertSame('0600', substr(\sprintf('%o', fileperms($file)), -4), basename($file) . ' is private');
        }
        self::assertSame('0700', substr(\sprintf('%o', fileperms($this->b->paths->root)), -4));
        self::assertSame(['backup.run'], $this->b->auditActions());
        self::assertGreaterThan(0, $seed['png']);
    }

    public function testASecondBackupCopiesOnlyWhatIsNew(): void
    {
        $this->b->seed();
        $first = $this->b->backups->run('manual');
        self::assertSame(5, $first['media_copied']);
        $this->b->clock->time += 60;
        $second = $this->b->backups->run('manual');
        self::assertSame(0, $second['media_copied'], 'unchanged files are not copied again');
        self::assertSame(5, $second['media_files']);
    }

    public function testSecretsConfigurationSessionsAndAddressHashesNeverEnterABackup(): void
    {
        $this->b->seed();
        // Private files next to the backups, as on a server: they are outside every backup boundary.
        foreach (['config.php', '.env', 'sessions/sess_abc'] as $private) {
            $path = $this->b->tmp . '/storage/' . $private;
            @mkdir(\dirname($path), 0775, true);
            file_put_contents($path, 'SECRET-MARKER-' . $private);
        }
        $run = $this->b->backups->run('manual');
        $everything = '';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->b->paths->root, \FilesystemIterator::SKIP_DOTS));
        $names = [];
        foreach ($it as $file) {
            \assert($file instanceof \SplFileInfo);
            $names[] = $file->getFilename();
            $raw = (string) file_get_contents($file->getPathname());
            $everything .= str_ends_with($file->getFilename(), '.gz') ? (string) gzdecode($raw) : $raw;
        }
        self::assertStringNotContainsString('SECRET-MARKER', $everything);
        self::assertStringNotContainsString('ADDRESS1', $everything, 'hashed visitor addresses are not backed up');
        self::assertStringNotContainsString('SUBJECT1', $everything);
        self::assertStringNotContainsString(TestDatabaseSecrets::password(), $everything, 'the database password is not in a backup');
        self::assertSame([], array_values(array_intersect($names, ['config.php', '.env', 'sess_abc'])));
        self::assertStringContainsString('CREATE TABLE', $everything, 'the dump itself was scanned');
        self::assertSame([], $this->b->backups->verify($run['id']));
    }

    public function testABackupFailureIsAuditedLeavesNoPartialSetAndShowsOnTheDashboard(): void
    {
        $this->b->seed();
        $this->b->dumpConnection = static fn(): PDO => throw new RuntimeException('database went away (test)');
        try {
            $this->b->backups->run('scheduler');
            self::fail('expected a failure');
        } catch (BackupError $e) {
            self::assertSame('io', $e->reason);
        }
        self::assertSame([], $this->b->backups->sets(), 'no manifest, no set');
        self::assertSame([], glob($this->b->paths->root . '/db/*') ?: []);
        self::assertSame(['backup.failed'], $this->b->auditActions());
        $st = $this->b->backups->status();
        self::assertTrue($st['overdue']);
        self::assertNotNull($st['last_failure']);

        $this->b->dumpConnection = null;
        $this->b->clock->time += 120;
        $this->b->backups->run('scheduler');
        sleep(1);
        $st = $this->b->backups->status();
        self::assertFalse($st['overdue']);
        self::assertSame(['backup.failed', 'backup.run'], $this->b->auditActions());
    }

    public function testOnlyOneBackupOrRestoreRunsAtATime(): void
    {
        $this->b->paths->ensure();
        $lock = fopen($this->b->paths->lockFile(), 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->b->backups->run('manual');
            self::fail('ran while locked');
        } catch (BackupError $e) {
            self::assertSame('locked', $e->reason);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        self::assertNotEmpty($this->b->backups->run('manual')['id']);
    }

    public function testABackupWaitsForARunningMigrationAndNeverSeesAHalfChangedSchema(): void
    {
        $this->b->seed();
        $other = BackupFixture::connect(TestDatabaseSecrets::name());
        self::assertSame(1, Sql::int($other, "SELECT GET_LOCK('nova_migrate', 0)"), 'another connection is "migrating"');
        try {
            $this->b->backups->run('manual');
            self::fail('backed up during a migration');
        } catch (BackupError $e) {
            self::assertSame('locked', $e->reason);
        }
        self::assertSame([], $this->b->backups->sets());
        // The pre-migration copy runs inside the migration itself and is exempt.
        self::assertNotEmpty($this->b->backups->run('pre_migration', 'cli', false)['id']);
        Sql::run($other, "SELECT RELEASE_LOCK('nova_migrate')");
        self::assertNotEmpty($this->b->backups->run('manual')['id']);
    }

    // ---- retention

    public function testRetentionPrunesOldSetsAndTheirUnsharedFilesButNeverTheNewest(): void
    {
        $this->b->seed();
        $ids = [];
        for ($day = 0; $day < 40; ++$day) {
            $this->b->clock->time = 1_800_000_000 + $day * 86_400;
            $ids[] = $this->b->backups->run('scheduler', 'cli', false)['id'];
        }
        self::assertCount(40, $this->b->backups->sets());
        $deleted = $this->b->backups->prune();
        $left = array_column($this->b->backups->sets(), 'id');
        self::assertNotEmpty($deleted);
        self::assertSame(array_values(array_diff($ids, $deleted)), array_reverse($left));
        self::assertContains($ids[39], $left);
        foreach (\array_slice($ids, 33) as $id) {
            self::assertContains($id, $left, 'the last seven days are kept');
        }
        self::assertNotContains($ids[0], $left);
        foreach ($deleted as $id) {
            self::assertFileDoesNotExist($this->b->paths->dump($id));
            self::assertFileDoesNotExist($this->b->paths->manifest($id));
        }
        // Files still named by a kept set stay in the store.
        foreach ($this->b->backups->sets()[0]['manifest']['media']['files'] ?? [] as $f) {
            self::assertFileExists($this->b->paths->store() . '/' . $f['path']);
        }
        self::assertSame([], $this->b->backups->verify($ids[39]));
        self::assertContains('backup.prune', $this->b->auditActions());
    }

    public function testTheStoreIsSweptOfFilesNoRemainingSetNames(): void
    {
        $seed = $this->b->seed();
        $this->b->backups->run('scheduler', 'cli', false);
        $this->b->clock->time += 86_400 * 200;
        // The image is deleted and trashed for good; later sets no longer name it.
        Sql::run($this->b->f->pdo, 'DELETE FROM media WHERE id = ?', [$seed['png']]);
        for ($i = 0; $i < 12; ++$i) {
            $this->b->clock->time += 86_400;
            $this->b->backups->run('scheduler', 'cli', false);
        }
        $storeBefore = glob($this->b->paths->store() . '/*/*') ?: [];
        self::assertCount(5, $storeBefore);
        $this->b->backups->prune();
        self::assertCount(1, glob($this->b->paths->store() . '/*/*') ?: [], 'only the PDF is still named by a kept set');
    }

    // ---- restore

    public function testADatabaseRestoresIntoAnEmptyDatabaseExactlyAsItWas(): void
    {
        $this->b->seed();
        $run = $this->b->backups->run('manual');
        $target = BackupFixture::target('A');

        $r = $this->b->restores->restoreDatabase($run['id'], $target);
        self::assertSame('restored', $r['status']);
        self::assertSame([], $this->b->restores->verifyTarget($run['id'], $target));

        $a = BackupFixture::connect($target);
        self::assertSame(self::fingerprints($this->b->f->pdo), self::fingerprints($a), 'every table has the same rows and content as the source');
        // Spot checks that do not depend on the fingerprint code.
        self::assertSame(Sql::scalar($this->b->f->pdo, 'SELECT message FROM inquiry'), Sql::scalar($a, 'SELECT message FROM inquiry'));
        self::assertStringContainsString("DROP TABLE x; --\n", (string) Sql::scalar($a, 'SELECT message FROM inquiry'));
        self::assertSame("QZ O'1", Sql::scalar($a, "SELECT designation FROM product WHERE designation LIKE 'QZ O%'"));
        self::assertSame(Sql::scalar($this->b->f->pdo, 'SELECT GROUP_CONCAT(owner_ref ORDER BY id) FROM designation_key'), Sql::scalar($a, 'SELECT GROUP_CONCAT(owner_ref ORDER BY id) FROM designation_key'), 'generated columns are recomputed');
        self::assertSame(Sql::scalar($this->b->f->pdo, 'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = "FOREIGN KEY"'), Sql::scalar($a, 'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = "FOREIGN KEY"'), 'the schema, constraints included, is the same');
        self::assertSame([0, 0], [Sql::int($a, 'SELECT COUNT(*) FROM inquiry_attempt'), Sql::int($a, 'SELECT COUNT(*) FROM login_throttle')], 'the throttle tables exist, empty: the application works at once');
        self::assertSame(1, Sql::int($this->b->f->pdo, 'SELECT COUNT(*) FROM inquiry_attempt'), 'and the source still has its rows');
        self::assertSame(['backup.run', 'restore.db'], $this->b->auditActions());

        // The restored database accepts new work (auto-increment continues, constraints hold).
        $a->exec("INSERT INTO inquiry (lang, name, phone, match_state) VALUES ('en', 'after restore', '0912', 'no_code')");
        self::assertSame(2, Sql::int($a, 'SELECT COUNT(*) FROM inquiry'));
    }

    public function testRepeatingARestoreIsIdempotent(): void
    {
        $this->b->seed();
        $run = $this->b->backups->run('manual');
        $target = BackupFixture::target('A');
        $this->b->restores->restoreDatabase($run['id'], $target);
        $before = self::fingerprints(BackupFixture::connect($target));

        $again = $this->b->restores->restoreDatabase($run['id'], $target);
        self::assertSame('already_restored', $again['status']);
        self::assertSame($before, self::fingerprints(BackupFixture::connect($target)));
        self::assertSame([], $this->b->restores->verifyTarget($run['id'], $target));

        // The same set can be restored again into a fresh empty database, with the same result.
        $other = BackupFixture::target('B');
        self::assertSame('restored', $this->b->restores->restoreDatabase($run['id'], $other)['status']);
        self::assertSame($before, self::fingerprints(BackupFixture::connect($other)));

        // A different set is refused into a database that already holds a restore: never merged.
        $this->b->clock->time += 60;
        $second = $this->b->backups->run('manual');
        try {
            $this->b->restores->restoreDatabase($second['id'], $target);
            self::fail('merged two restores');
        } catch (BackupError $e) {
            self::assertSame('not_empty', $e->reason);
        }
        self::assertSame($before, self::fingerprints(BackupFixture::connect($target)));
    }

    public function testRestoreVerifyDetectsADifference(): void
    {
        $this->b->seed();
        $run = $this->b->backups->run('manual');
        $target = BackupFixture::target('A');
        $this->b->restores->restoreDatabase($run['id'], $target);
        BackupFixture::connect($target)->exec("UPDATE product SET designation = 'TAMPERED' WHERE designation = 'QZ 2'");
        $problems = $this->b->restores->verifyTarget($run['id'], $target);
        self::assertNotSame([], $problems);
        self::assertStringContainsString('product', $problems[0]);
    }

    public function testWrongTargetsAreRefusedBeforeAnythingChanges(): void
    {
        $this->b->seed();
        $run = $this->b->backups->run('manual');
        $live = TestDatabaseSecrets::name();
        $b = BackupFixture::target('B');
        BackupFixture::connect($b)->exec('CREATE TABLE precious (id INT PRIMARY KEY)');
        BackupFixture::connect($b)->exec('INSERT INTO precious VALUES (1)');
        $liveBefore = self::fingerprints($this->b->f->pdo);

        $cases = [
            'invalid_target' => [['', false, null], ['a;DROP TABLE x', false, null], ['../etc', false, null], ['nova-live', false, null], [str_repeat('a', 65), false, null], ['nova_rt_does_not_exist_zz', false, null]],
            'not_empty' => [[$b, false, null]],
            'wrong_target' => [[$live, false, null], [$live, true, null], [$live, true, 'nova'], [$live, false, $live]],
        ];
        foreach ($cases as $reason => $attempts) {
            foreach ($attempts as [$target, $replace, $confirm]) {
                try {
                    $this->b->restores->restoreDatabase($run['id'], $target, $replace, $confirm);
                    self::fail("accepted target {$target}");
                } catch (BackupError $e) {
                    self::assertSame($reason, $e->reason, $target);
                }
            }
        }
        self::assertSame(1, Sql::int(BackupFixture::connect($b), 'SELECT COUNT(*) FROM precious'), 'a non-empty target is untouched');
        self::assertSame(['precious'], array_map(strval(...), Sql::column(BackupFixture::connect($b), 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')));
        self::assertSame($liveBefore, self::fingerprints($this->b->f->pdo), 'the live database is untouched');
        self::assertContains('restore.refused', $this->b->auditActions());
        self::assertNotContains('restore.db', $this->b->auditActions());

        foreach (['', 'B-1', '../../etc/passwd', 'B-20261015-031500-ab12/../x', 'b-20261015-031500-ab12'] as $id) {
            try {
                $this->b->restores->restoreDatabase($id, BackupFixture::target('A'));
                self::fail("accepted set id {$id}");
            } catch (BackupError $e) {
                self::assertContains($e->reason, ['traversal', 'not_found'], $id);
            }
        }
        try {
            $this->b->restores->restoreDatabase('B-20200101-000000-0000', BackupFixture::target('A'));
            self::fail('restored a set that does not exist');
        } catch (BackupError $e) {
            self::assertSame('not_found', $e->reason);
        }
    }

    public function testAnEmptyConfiguredDatabaseOnANewHostNeedsNoConfirmation(): void
    {
        $this->b->seed();
        $run = $this->b->backups->run('manual');
        $good = self::fingerprints($this->b->f->pdo);
        // The disaster: the live database is gone and the new host's configured database is empty.
        $this->b->f->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (Sql::column($this->b->f->pdo, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $t) {
            $this->b->f->pdo->exec('DROP TABLE `' . $t . '`');
        }
        $this->b->f->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $r = $this->b->restores->restoreDatabase($run['id'], TestDatabaseSecrets::name());
        self::assertSame('restored', $r['status']);
        self::assertSame($good, self::fingerprints($this->b->f->pdo));
        self::assertSame([], array_filter($this->b->backups->sets(), static fn(array $s): bool => $s['trigger'] === 'pre_restore'), 'nothing to lose, so no safety copy was needed');
        self::assertSame([], $this->b->restores->verifyTarget($run['id'], TestDatabaseSecrets::name()));
    }

    public function testReplacingTheLiveDatabaseNeedsExplicitConfirmationAndMakesASafetyCopyFirst(): void
    {
        $this->b->seed();
        $run = $this->b->backups->run('manual');
        $good = self::fingerprints($this->b->f->pdo);
        Sql::run($this->b->f->pdo, "DELETE FROM inquiry");
        Sql::run($this->b->f->pdo, "UPDATE product SET designation = 'DAMAGED' WHERE designation = 'QZ 2'");
        $damaged = self::fingerprints($this->b->f->pdo);
        self::assertNotSame($good, $damaged);
        $this->b->clock->time += 60;

        $live = TestDatabaseSecrets::name();
        $r = $this->b->restores->restoreDatabase($run['id'], $live, true, $live);
        self::assertSame('restored', $r['status']);
        self::assertSame($good, self::fingerprints($this->b->f->pdo), 'the live database is back to the backed-up state');

        $pre = array_values(array_filter($this->b->backups->sets(), static fn(array $s): bool => $s['trigger'] === 'pre_restore'));
        self::assertCount(1, $pre, 'a pre_restore safety copy was made first');
        // The safety copy holds the damaged state, so the replacement can itself be undone.
        $a = BackupFixture::target('A');
        $this->b->restores->restoreDatabase($pre[0]['id'], $a);
        self::assertSame($damaged, self::fingerprints(BackupFixture::connect($a)));
    }

    public function testCorruptOrIncompleteBackupsAreRejectedAndTheTargetStaysEmpty(): void
    {
        $this->b->seed();
        $target = BackupFixture::target('A');
        $isEmpty = static fn(): bool => Sql::column(BackupFixture::connect($target), 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') === [];

        // 1. A flipped byte in the dump.
        $run = $this->b->backups->run('manual');
        $gz = $this->b->paths->dump($run['id']);
        $raw = (string) file_get_contents($gz);
        file_put_contents($gz, substr_replace($raw, \chr(\ord($raw[200]) ^ 0xFF), 200, 1));
        self::assertNotSame([], $this->b->backups->verify($run['id']));
        $this->expectRestoreRefusal($run['id'], $target, 'corrupt');

        // 2. A truncated dump, with a manifest that was rewritten to match: the missing end marker still gives it away.
        $this->b->clock->time += 60;
        $run2 = $this->b->backups->run('manual');
        $gz2 = $this->b->paths->dump($run2['id']);
        $whole = (string) gzdecode((string) file_get_contents($gz2));
        $cut = substr($whole, 0, (int) (\strlen($whole) * 0.6));
        file_put_contents($gz2, gzencode($cut));
        $sha = (string) hash_file('sha256', $gz2);
        $bytes = (int) filesize($gz2);
        $this->b->rewriteManifest($run2['id'], static function (array $m) use ($sha, $bytes): array {
            /** @var array{db: array<string, mixed>} $m */
            $m['db']['sha256'] = $sha;
            $m['db']['bytes'] = $bytes;

            return $m;
        });
        $problems = $this->b->backups->verify($run2['id']);
        self::assertStringContainsString('cut short', $problems[0]);
        $this->expectRestoreRefusal($run2['id'], $target, 'incomplete');

        // 3. A missing dump.
        $this->b->clock->time += 60;
        $run3 = $this->b->backups->run('manual');
        unlink($this->b->paths->dump($run3['id']));
        $this->expectRestoreRefusal($run3['id'], $target, 'incomplete');

        // 4. A manifest edited without its checksum.
        $this->b->clock->time += 60;
        $run4 = $this->b->backups->run('manual');
        $path = $this->b->paths->manifest($run4['id']);
        file_put_contents($path, str_replace('"trigger": "manual"', '"trigger": "scheduler"', (string) file_get_contents($path)));
        $this->expectRestoreRefusal($run4['id'], $target, 'corrupt');

        // 5. A set from a newer schema than this code.
        $this->b->clock->time += 60;
        $run5 = $this->b->backups->run('manual');
        $this->b->rewriteManifest($run5['id'], static function (array $m): array {
            $m['schema_version'] = 999;

            return $m;
        });
        $this->expectRestoreRefusal($run5['id'], $target, 'schema_newer');

        self::assertTrue($isEmpty(), 'no refused restore left anything in the target');
        $broken = array_filter($this->b->backups->sets(), static fn(array $s): bool => !$s['ok']);
        self::assertCount(1, $broken, 'the set with the damaged manifest is listed as broken');
        $restoreActions = array_values(array_unique(array_filter($this->b->auditActions(), static fn(string $a): bool => str_starts_with($a, 'restore.'))));
        sort($restoreActions);
        self::assertSame(['restore.failed', 'restore.refused'], $restoreActions);
    }

    private function expectRestoreRefusal(string $id, string $target, string $reason): void
    {
        try {
            $this->b->restores->restoreDatabase($id, $target);
            self::fail("restored {$id}");
        } catch (BackupError $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public function testAnInterruptedRestoreCleansUpAfterItselfAndATrueCrashNeedsCleanup(): void
    {
        $this->b->seed();
        $run = $this->b->backups->run('manual');
        $target = BackupFixture::target('A');

        // A failure part-way through (here a thrown exception after 12 statements) leaves the target empty again.
        try {
            $this->b->restores->restoreDatabase($run['id'], $target, afterStatement: static function (int $n): void {
                if ($n === 12) {
                    throw new RuntimeException('simulated interruption');
                }
            });
            self::fail('expected an interruption');
        } catch (BackupError $e) {
            self::assertSame('io', $e->reason);
        }
        self::assertSame([], Sql::column(BackupFixture::connect($target), 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'));
        self::assertContains('restore.failed', $this->b->auditActions());
        self::assertSame('restored', $this->b->restores->restoreDatabase($run['id'], $target)['status'], 'and a clean retry succeeds');

        // A real crash (the process dies) cannot clean up: it leaves the marker and half the tables.
        $c = BackupFixture::target('C');
        $db = BackupFixture::connect($c);
        $db->exec('CREATE TABLE _nova_restore (set_id VARCHAR(40) NOT NULL, set_sha256 CHAR(64) NOT NULL, state VARCHAR(12) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL)');
        $db->exec("INSERT INTO _nova_restore VALUES ('x', '" . str_repeat('0', 64) . "', 'running', UTC_TIMESTAMP(), NULL)");
        $db->exec('CREATE TABLE app_user (id INT PRIMARY KEY)');
        $this->expectRestoreRefusal($run['id'], $c, 'interrupted');
        self::assertCount(2, Sql::column($db, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'), 'refused without touching it');
        self::assertSame(2, $this->b->restores->cleanup($c));
        self::assertSame([], Sql::column($db, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'));
        self::assertSame('restored', $this->b->restores->restoreDatabase($run['id'], $c)['status']);
        self::assertContains('restore.cleanup', $this->b->auditActions());
    }

    public function testCleanupRefusesADatabaseThatHoldsNoInterruptedRestore(): void
    {
        $b = BackupFixture::target('B');
        BackupFixture::connect($b)->exec('CREATE TABLE keepme (id INT PRIMARY KEY)');
        foreach ([[$b, false, null, 'wrong_target'], [TestDatabaseSecrets::name(), false, null, 'wrong_target'], [TestDatabaseSecrets::name(), true, 'other', 'wrong_target'], ['bad name', false, null, 'invalid_target']] as [$target, $replace, $confirm, $reason]) {
            try {
                $this->b->restores->cleanup($target, $replace, $confirm);
                self::fail("cleaned {$target}");
            } catch (BackupError $e) {
                self::assertSame($reason, $e->reason, $target);
            }
        }
        self::assertSame(['keepme'], array_map(strval(...), Sql::column(BackupFixture::connect($b), 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')));
    }

    // ---- releases

    public function testRetainedReleasesAreNeverTouchedByBackupPruneOrRestore(): void
    {
        $this->b->seed();
        $this->b->addRelease('R-20261001-000000-aaaa', 'superseded');
        $this->b->addRelease('R-20261002-000000-bbbb', 'live');
        $before = $this->b->treeHash($this->b->tmp . '/site');

        $run = $this->b->backups->run('manual');
        $this->b->backups->prune();
        $target = BackupFixture::target('A');
        $this->b->restores->restoreDatabase($run['id'], $target);

        self::assertSame($before, $this->b->treeHash($this->b->tmp . '/site'), 'backup, prune and restore leave every release byte-identical');
        // The restored database knows the same retained releases; both are still on disk to roll back to.
        $ids = Sql::column(BackupFixture::connect($target), "SELECT release_id FROM publish_record WHERE status IN ('live', 'superseded') ORDER BY id");
        self::assertSame(['R-20261001-000000-aaaa', 'R-20261002-000000-bbbb'], $ids);
        foreach ($ids as $id) {
            self::assertFileExists($this->b->tmp . "/site/releases/{$id}/manifest.json");
        }
    }

    // ---- scheduler fallback

    public function testTheFallbackBacksUpOnlyWhenEnabledAndNoRecentSuccessAndDoesNotLoopOnFailure(): void
    {
        $this->b->seed();
        $off = new BackupScheduler($this->b->f->pdo, $this->b->backups, new BackupConfig(fallback: false), $this->b->clock);
        self::assertFalse($off->fallbackDue(), 'never when switched off: the scheduler is the preferred path');
        self::assertNull($off->runFallbackIfDue());

        $on = new BackupScheduler($this->b->f->pdo, $this->b->backups, new BackupConfig(fallback: true), $this->b->clock);
        self::assertTrue($on->fallbackDue(), 'no backup yet');
        $id = $on->runFallbackIfDue();
        self::assertNotNull($id);
        self::assertSame('fallback', $this->b->backups->sets()[0]['trigger']);
        self::assertFalse($on->fallbackDue(), 'a backup newer than 24 h exists, so a working scheduler is not duplicated');

        $this->b->clock->time += 90_000;
        self::assertTrue($on->fallbackDue());
        $this->b->dumpConnection = static fn(): PDO => throw new RuntimeException('down');
        self::assertNull($on->runFallbackIfDue(), 'a failing fallback never breaks the request');
        $this->b->alignFailureTimes();
        $this->b->clock->time += 60;
        self::assertFalse($on->fallbackDue(), 'and is not retried within the hour');
        $this->b->clock->time += 4_000;
        self::assertTrue($on->fallbackDue());
    }

    private function overdue(): bool
    {
        return $this->b->backups->status()['overdue'];
    }

    public function testTheDashboardStatusWarnsWhenABackupIsOverdue(): void
    {
        self::assertTrue($this->overdue(), 'never backed up is overdue');
        self::assertNull($this->b->backups->status()['last']);
        $this->b->backups->run('manual');
        self::assertFalse($this->overdue());
        $this->b->clock->time += 27 * 3600;
        self::assertTrue($this->overdue());
        self::assertSame(27, $this->b->backups->status()['hours_since']);
        // A safety copy is not a scheduled backup.
        $this->b->backups->run('pre_restore', 'cli', false);
        self::assertTrue($this->overdue(), 'a safety copy is not a scheduled backup');
    }
}
