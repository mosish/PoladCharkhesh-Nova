<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Backup;

use Nova\Backup\BackupConfig;
use Nova\Backup\BackupManifest;
use Nova\Backup\BackupPaths;
use Nova\Backup\BackupService;
use Nova\Backup\PreMigrationBackupGuard;
use Nova\Backup\RestoreService;
use Nova\Core\Database;
use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Migration\MigrationError;
use Nova\Migration\Migrator;
use Nova\Tests\Integration\Publish\TestClock;
use Nova\Tests\Integration\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Before a migration changes a non-empty database, a verified pre_migration set must exist, its id is recorded with the
 * migration, and the set restores the database exactly as it was before (05 §3, D23).
 */
final class PreMigrationGuardTest extends TestCase
{
    private string $tmp;
    private string $oldMigrations;
    private string $errorLog = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nova-pm-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/old-migrations', 0775, true);
        mkdir($this->tmp . '/media', 0775, true);
        $this->oldMigrations = $this->tmp . '/old-migrations';
        foreach (['0001', '0002', '0003', '0004'] as $v) {
            foreach (glob(Paths::fromHere()->migrations() . "/{$v}_*.sql") ?: [] as $f) {
                copy($f, $this->oldMigrations . '/' . basename($f));
            }
        }
        $this->errorLog = (string) ini_set('error_log', $this->tmp . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
        Files::removeTree($this->tmp);
    }

    private function service(PDO $pdo, ?\Closure $dump = null): BackupService
    {
        return new BackupService($pdo, $dump ?? static fn(): PDO => Database::connect(TestDatabase::config()), new BackupPaths($this->tmp . '/backups'), new BackupConfig(), $this->tmp . '/media', new TestClock(1_800_000_000), 'test', static fn(): array => []);
    }

    public function testANonEmptyDatabaseIsBackedUpAndVerifiedBeforeItsMigrationsAndTheSetRestoresItAsItWas(): void
    {
        $pdo = TestDatabase::empty();
        (new Migrator($pdo, $this->oldMigrations, 'test'))->migrate(new \Nova\Migration\UnavailableBackupGuard());
        self::assertSame(4, Sql::int($pdo, 'SELECT MAX(version) FROM schema_migrations'));
        $pdo->exec("INSERT INTO family (slug, name_fa, name_en, short_fa, short_en, definition_fa, definition_en) VALUES ('pre', 'پیش', 'Before', 'p', 'b', 'تعریف', 'definition')");

        $backups = $this->service($pdo);
        $applied = (new Migrator($pdo, Paths::fromHere()->migrations(), 'test'))->migrate(new PreMigrationBackupGuard($backups));

        self::assertSame(5, \count($applied), 'migrations 0005 to 0009 ran after the backup');
        $sets = $backups->sets();
        self::assertCount(1, $sets);
        self::assertSame('pre_migration', $sets[0]['trigger']);
        $set = $sets[0]['id'];
        self::assertSame([], $backups->verify($set));
        self::assertSame(4, BackupManifest::read(new BackupPaths($this->tmp . '/backups'), $set)['schema_version'], 'the set holds the schema as it was before the migrations');
        self::assertSame([$set], array_values(array_unique(array_map(strval(...), Sql::column($pdo, 'SELECT backup_ref FROM schema_migrations WHERE version > 4')))), 'every migration of the run records the backup it relied on');

        // Restoring the safety copy reproduces the pre-migration database, which a failed migration could be recovered from.
        $name = BackupFixture::target('A');
        $restore = new RestoreService($pdo, TestDatabase::config(), new BackupPaths($this->tmp . '/backups'), $backups, 9);
        self::assertSame('restored', $restore->restoreDatabase($set, $name)['status']);
        $a = BackupFixture::connect($name);
        self::assertSame(4, Sql::int($a, 'SELECT MAX(version) FROM schema_migrations'));
        self::assertSame('Before', Sql::scalar($a, "SELECT name_en FROM family WHERE slug = 'pre'"));
        self::assertSame(0, Sql::int($a, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('media', 'inquiry')"), 'the later tables do not exist in the pre-migration copy');
    }

    public function testAMigrationIsRefusedAndNothingChangesWhenTheBackupCannotBeMade(): void
    {
        $pdo = TestDatabase::empty();
        (new Migrator($pdo, $this->oldMigrations, 'test'))->migrate(new \Nova\Migration\UnavailableBackupGuard());
        $failing = $this->service($pdo, static fn(): PDO => throw new RuntimeException('disk full (test)'));
        try {
            (new Migrator($pdo, Paths::fromHere()->migrations(), 'test'))->migrate(new PreMigrationBackupGuard($failing));
            self::fail('migrated without a backup');
        } catch (MigrationError $e) {
            self::assertStringContainsString('could not be made', $e->getMessage());
        }
        self::assertSame(4, Sql::int($pdo, 'SELECT MAX(version) FROM schema_migrations'));
        self::assertSame(0, Sql::int($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'media'"));
        self::assertSame(1, Sql::int($pdo, "SELECT COUNT(*) FROM audit_event WHERE action = 'backup.failed'"), 'the failure is audited');
        self::assertSame([], $failing->sets());
    }

    public function testAnEmptyDatabaseNeedsNoBackup(): void
    {
        $pdo = TestDatabase::empty();
        $applied = (new Migrator($pdo, Paths::fromHere()->migrations(), 'test'))->migrate(new PreMigrationBackupGuard($this->service($pdo, static fn(): PDO => throw new RuntimeException('must not be called'))));
        self::assertCount(9, $applied);
        self::assertSame(Migrator::EMPTY_DATABASE, Sql::scalar($pdo, 'SELECT backup_ref FROM schema_migrations WHERE version = 1'));
    }
}
