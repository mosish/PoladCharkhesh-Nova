<?php

declare(strict_types=1);

namespace Nova\Backup;

use Nova\Audit\AuditLog;
use Nova\Core\Database;
use Nova\Core\DatabaseConfig;
use Nova\Core\Sql;
use PDO;
use Throwable;

/**
 * Restore (05 §18: "restore must be proven").
 *
 * **Database.** The target is always named explicitly (`--target`), never
 * guessed:
 * - the name must be a plain identifier and the database must already exist
 *   (a restore never creates databases);
 * - the target must be **empty**. A non-empty database is refused, except
 *   (a) the same set restored before (verified, reported as already
 *   restored: repeating a restore is harmless), or (b) the configured live
 *   database, which needs `replaceLive` *and* the live name typed again as
 *   confirmation, and is preceded by an automatic `pre_restore` safety
 *   backup that must succeed. An *empty* configured database (a new host
 *   after a disaster) needs neither: there is nothing to lose;
 * - the set must pass full verification first (checksums, completeness,
 *   statement count), and must not be from a newer schema than this code.
 *
 * While it runs, a marker table `_nova_restore` says "running". If the
 * process dies, the marker makes the next attempt refuse until
 * `cleanup()` has emptied the half-restored target; a failure inside the
 * process cleans up by itself. After the last statement every table is
 * compared with the manifest (row count and content checksum) before the
 * marker becomes "done".
 *
 * **Media.** `restoreMedia` copies the files a manifest names, from the
 * backup store or a full archive, into a media folder that is empty or holds
 * only media (`MediaFiles::assertTarget`), verifying every hash.
 *
 * Releases are never touched. Every outcome, including refusals, is audited.
 *
 * @phpstan-import-type Manifest from BackupManifest
 */
final class RestoreService
{
    public const string MARKER = '_nova_restore';

    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseConfig $connection,
        private readonly BackupPaths $paths,
        private readonly BackupService $backups,
        private readonly int $codeSchemaVersion,
    ) {}

    /**
     * @param (\Closure(int): void)|null $afterStatement test hook: called after each executed statement (a throw simulates an interruption)
     *
     * @return array{status: string, tables: int, rows: int, schema_version: int}
     *
     * @throws BackupError
     */
    public function restoreDatabase(string $id, string $target, bool $replaceLive = false, ?string $confirm = null, ?\Closure $afterStatement = null, string $origin = 'cli'): array
    {
        try {
            return $this->doRestoreDatabase($id, $target, $replaceLive, $confirm, $afterStatement, $origin);
        } catch (BackupError $e) {
            $this->audit(\in_array($e->reason, ['invalid_target', 'not_empty', 'wrong_target', 'interrupted', 'schema_newer', 'locked', 'not_found'], true) ? 'restore.refused' : 'restore.failed', $id, ['target' => $target, 'reason' => $e->reason, 'origin' => $origin, 'error' => mb_substr($e->getMessage(), 0, 400)]);

            throw $e;
        }
    }

    /** @return array{status: string, tables: int, rows: int, schema_version: int} */
    private function doRestoreDatabase(string $id, string $target, bool $replaceLive, ?string $confirm, ?\Closure $afterStatement, string $origin): array
    {
        $live = $this->connection->name;
        self::assertName($target);
        $manifest = BackupManifest::read($this->paths, $id);
        if ($manifest['schema_version'] > $this->codeSchemaVersion) {
            throw new BackupError('schema_newer', "The backup is from schema {$manifest['schema_version']}, newer than this code ({$this->codeSchemaVersion}); restore it with the newer release.");
        }
        $problems = $this->backups->verify($id);
        if ($problems !== []) {
            $first = $problems[0];
            throw new BackupError(str_contains($first, 'cut short') || str_contains($first, 'missing') ? 'incomplete' : 'corrupt', $first);
        }

        $lock = $this->lock();
        /** @var resource|null $lock */
        try {
            $db = $this->connect($target);
            $tables = self::tables($db);
            $marker = \in_array(self::MARKER, $tables, true) ? Sql::row($db, 'SELECT state, set_sha256 FROM ' . DatabaseDump::id(self::MARKER)) : null;
            if ($marker !== null && $marker['state'] === 'running') {
                throw new BackupError('interrupted', "A restore into {$target} was interrupted. Run restore:cleanup --target={$target} first.");
            }
            if ($tables !== [] && $marker !== null && $marker['set_sha256'] === $manifest['db']['sha256']) {
                try {
                    $this->compare($db, $manifest);
                } catch (BackupError $e) {
                    // Restored before, but no longer identical (it has been used since): never restore over it.
                    throw new BackupError('not_empty', "{$target} holds an earlier restore of this set that has changed since; it is left alone. ({$e->getMessage()})", $e);
                }

                return $this->done('already_restored', $id, $target, $manifest, $origin);
            }
            if ($tables !== [] && $target === $live && !($replaceLive && $confirm === $live)) {
                throw new BackupError('wrong_target', "{$target} is the live database and it is not empty. Restoring over it needs --replace-live and --confirm={$live}.");
            }
            if ($tables !== [] && $target !== $live) {
                throw new BackupError('not_empty', "{$target} is not empty. A restore needs an empty database.");
            }
            if ($target === $live && $tables !== []) {
                // Replacing a live database that holds data: a fresh safety copy first, and it must succeed.
                $this->lockRelease($lock);
                $lock = null;
                $this->backups->run('pre_restore', $origin, false);
                $lock = $this->lock();
                $this->dropAll($db);
            }

            $this->runDump($db, $id, $manifest, $afterStatement);

            return $this->done('restored', $id, $target, $manifest, $origin);
        } finally {
            if ($lock !== null) {
                $this->lockRelease($lock);
            }
        }
    }

    /**
     * Empties a target whose restore was interrupted (it must carry the marker; any other database is refused).
     *
     * @throws BackupError
     */
    public function cleanup(string $target, bool $replaceLive = false, ?string $confirm = null, string $origin = 'cli'): int
    {
        try {
            self::assertName($target);
            if ($target === $this->connection->name && !($replaceLive && $confirm === $target)) {
                throw new BackupError('wrong_target', "{$target} is the live database. Cleaning it needs --replace-live and --confirm={$target}.");
            }
            $db = $this->connect($target);
            if (!\in_array(self::MARKER, self::tables($db), true)) {
                throw new BackupError('wrong_target', "{$target} holds no interrupted restore; nothing is cleaned.");
            }
            $dropped = $this->dropAll($db);
            $this->audit('restore.cleanup', $target, ['tables' => $dropped, 'origin' => $origin]);

            return $dropped;
        } catch (BackupError $e) {
            $this->audit('restore.refused', $target, ['reason' => $e->reason, 'origin' => $origin, 'error' => mb_substr($e->getMessage(), 0, 400)]);

            throw $e;
        }
    }

    /**
     * Compares a restored database with a set, table by table.
     *
     * @return list<string> differences; empty means identical to the source at backup time
     */
    public function verifyTarget(string $id, string $target): array
    {
        self::assertName($target);
        $manifest = BackupManifest::read($this->paths, $id);
        try {
            $this->compare($this->connect($target), $manifest);
        } catch (BackupError $e) {
            return [$e->getMessage()];
        }

        return [];
    }

    /**
     * Restores the media and documents of a set into a media folder.
     *
     * @return array{restored: int, unchanged: int, missing_at_backup: int}
     *
     * @throws BackupError
     */
    public function restoreMedia(string $id, string $targetDir, ?string $archive = null, string $origin = 'cli'): array
    {
        try {
            $manifest = BackupManifest::read($this->paths, $id);
            $lock = $this->lock();
            try {
                $result = $archive === null
                    ? MediaFiles::restore($manifest['media']['files'], $this->paths->store(), $targetDir, $this->paths->root)
                    : MediaFiles::restoreArchive($archive, $targetDir, $this->paths->root, $this->paths->tmp());
            } finally {
                $this->lockRelease($lock);
            }
        } catch (BackupError $e) {
            $this->audit(\in_array($e->reason, ['wrong_target', 'traversal'], true) ? 'restore.refused' : 'restore.failed', $id, ['kind' => 'media', 'reason' => $e->reason, 'origin' => $origin, 'error' => mb_substr($e->getMessage(), 0, 400)]);

            throw $e;
        }
        $this->audit('restore.media', $id, ['origin' => $origin] + $result + ['missing_at_backup' => \count($manifest['media']['missing'])]);

        return $result + ['missing_at_backup' => \count($manifest['media']['missing'])];
    }

    /**
     * @param Manifest $manifest
     * @param (\Closure(int): void)|null $afterStatement
     */
    private function runDump(PDO $db, string $id, array $manifest, ?\Closure $afterStatement): void
    {
        $db->exec('CREATE TABLE ' . DatabaseDump::id(self::MARKER) . " (set_id VARCHAR(40) NOT NULL, set_sha256 CHAR(64) NOT NULL, state VARCHAR(12) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL) ENGINE=InnoDB");
        $db->prepare('INSERT INTO ' . DatabaseDump::id(self::MARKER) . " (set_id, set_sha256, state, started_at) VALUES (?, ?, 'running', UTC_TIMESTAMP())")->execute([$id, $manifest['db']['sha256']]);
        try {
            $n = 0;
            foreach (StatementReader::read($this->paths->dump($id)) as $statement) {
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                $db->exec($statement);
                if ($afterStatement !== null) {
                    $afterStatement(++$n);
                }
            }
            $this->compare($db, $manifest, fresh: true);
        } catch (Throwable $e) {
            // The target was empty (or deliberately replaced): putting it back to empty is the only safe state.
            try {
                $this->dropAll($db);
            } catch (Throwable $inner) {
                error_log('Nova restore: cleanup failed: ' . $inner->getMessage());
            }

            throw $e instanceof BackupError ? $e : new BackupError('io', 'The restore failed and the target was emptied again: ' . $e->getMessage(), $e);
        }
        $db->exec('UPDATE ' . DatabaseDump::id(self::MARKER) . " SET state = 'done', finished_at = UTC_TIMESTAMP()");
    }

    /**
     * @param Manifest $manifest
     *
     * @throws BackupError corrupt
     */
    private function compare(PDO $db, array $manifest, bool $fresh = false): void
    {
        $have = array_values(array_filter(self::tables($db), static fn(string $t): bool => $t !== self::MARKER));
        // Tables whose rows are not backed up still exist (empty) in a restored database.
        $want = array_values(array_unique([...array_keys($manifest['db']['tables']), ...array_map(strval(...), $manifest['excluded'])]));
        sort($want);
        sort($have);
        if ($have !== $want) {
            throw new BackupError('corrupt', 'The restored tables differ from the backup: ' . implode(', ', array_merge(array_diff($want, $have), array_diff($have, $want))));
        }
        // Only right after a restore are the throttle tables guaranteed empty; once the site is in use they fill again.
        foreach ($fresh ? $manifest['excluded'] : [] as $table) {
            if (Sql::int($db, 'SELECT COUNT(*) FROM ' . DatabaseDump::id((string) $table)) !== 0) {
                throw new BackupError('corrupt', "Table {$table} should restore empty but holds rows.");
            }
        }
        foreach ($manifest['db']['tables'] as $table => $expected) {
            $got = DatabaseDump::fingerprint($db, $table, $expected['max_id'] ?? null);
            if ($got['rows'] !== $expected['rows'] || !hash_equals($expected['checksum'], $got['checksum'])) {
                throw new BackupError('corrupt', "Table {$table} does not match the backup (rows {$got['rows']} of {$expected['rows']}, or content differs).");
            }
        }
    }

    /**
     * @param Manifest $manifest
     *
     * @return array{status: string, tables: int, rows: int, schema_version: int}
     */
    private function done(string $status, string $id, string $target, array $manifest, string $origin): array
    {
        $rows = array_sum(array_column($manifest['db']['tables'], 'rows'));
        $this->audit('restore.db', $id, ['target' => $target, 'status' => $status, 'tables' => \count($manifest['db']['tables']), 'rows' => $rows, 'origin' => $origin]);

        return ['status' => $status, 'tables' => \count($manifest['db']['tables']), 'rows' => $rows, 'schema_version' => $manifest['schema_version']];
    }

    private static function assertName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
            throw new BackupError('invalid_target', 'The target must be a database name of letters, digits and underscores.');
        }
    }

    private function connect(string $name): PDO
    {
        try {
            return Database::connect(new DatabaseConfig($this->connection->host, $this->connection->port, $name, $this->connection->user, $this->connection->password));
        } catch (Throwable) {
            throw new BackupError('invalid_target', "The database {$name} does not exist or is not accessible. Create it empty first; a restore never creates databases.");
        }
    }

    /** @return list<string> */
    private static function tables(PDO $db): array
    {
        return array_map(strval(...), Sql::column($db, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"));
    }

    private function dropAll(PDO $db): int
    {
        $tables = self::tables($db);
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            $db->exec('DROP TABLE ' . DatabaseDump::id($t));
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');

        return \count($tables);
    }

    /** @return resource */
    private function lock()
    {
        $this->paths->ensure();
        $lock = fopen($this->paths->lockFile(), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new BackupError('locked', 'Another backup or restore is running.');
        }

        return $lock;
    }

    /** @param resource|null $lock */
    private function lockRelease($lock): void
    {
        if ($lock === null) {
            return;
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /** @param array<string, mixed> $after */
    private function audit(string $action, string $label, array $after): void
    {
        try {
            (new AuditLog($this->pdo))->record(null, $action, 'backup', null, $label, null, $after);
        } catch (Throwable $e) {
            // On a new host the audit table does not exist until the restore has made it; that is not worth a log line.
            if (!str_contains($e->getMessage(), '42S02')) {
                error_log('Nova restore: audit failed: ' . $e->getMessage());
            }
        }
    }
}
