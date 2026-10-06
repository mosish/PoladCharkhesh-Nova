<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Migration;

use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Migration\MigrationError;
use Nova\Migration\MigrationFile;
use Nova\Migration\Migrator;
use Nova\Migration\UnavailableBackupGuard;
use Nova\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nova-migrations-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function file(string $name, string $sql): void
    {
        file_put_contents("{$this->dir}/{$name}", $sql);
    }

    public function testRealMigrationsApplyToAnEmptyDatabaseAndThenDoNothing(): void
    {
        $pdo = TestDatabase::empty();
        $migrator = new Migrator($pdo, Paths::fromHere()->migrations(), 'test');

        $applied = $migrator->migrate(new UnavailableBackupGuard());
        $all = array_map(static fn(MigrationFile $f): string => \sprintf('%04d_%s', $f->version, $f->name), MigrationFile::scan(Paths::fromHere()->migrations()));
        self::assertSame($all, $applied);
        self::assertSame([], $migrator->migrate(new UnavailableBackupGuard()), 'second run is a no-op');
        self::assertSame('applied', $migrator->status()[0]['state']);

        $row = Sql::row($pdo, 'SELECT backup_ref, finished_at FROM schema_migrations WHERE version = 1');
        self::assertIsArray($row);
        self::assertSame(Migrator::EMPTY_DATABASE, $row['backup_ref']);
        self::assertNotNull($row['finished_at']);
    }

    public function testNonEmptyDatabaseIsBackedUpOnceBeforeTheFirstChange(): void
    {
        $pdo = TestDatabase::empty();
        $this->file('0001_one.sql', "CREATE TABLE t1 (id INT PRIMARY KEY) ENGINE=InnoDB;\n");
        (new Migrator($pdo, $this->dir, 'test'))->migrate(new UnavailableBackupGuard());

        $this->file('0002_two.sql', "CREATE TABLE t2 (id INT PRIMARY KEY) ENGINE=InnoDB;\n");
        $this->file('0003_three.sql', "ALTER TABLE t2 ADD COLUMN v INT NULL;\n");
        $guard = new RecordingBackupGuard();
        $applied = (new Migrator($pdo, $this->dir, 'test'))->migrate($guard);

        self::assertSame(['0002_two', '0003_three'], $applied);
        self::assertSame(['before migration 0002'], $guard->calls);
        $refs = Sql::column($pdo, 'SELECT backup_ref FROM schema_migrations WHERE version > 1');
        self::assertSame(['backup-test-1', 'backup-test-1'], $refs);
    }

    public function testNoBackupMeansNoMigrationOnANonEmptyDatabase(): void
    {
        $pdo = TestDatabase::empty();
        $pdo->exec('CREATE TABLE existing (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->file('0001_one.sql', "CREATE TABLE t1 (id INT PRIMARY KEY) ENGINE=InnoDB;\n");

        try {
            (new Migrator($pdo, $this->dir, 'test'))->migrate(new UnavailableBackupGuard());
            self::fail('migrated without a backup');
        } catch (MigrationError $e) {
            self::assertStringContainsString('backup is required', $e->getMessage());
        }
        self::assertSame(0, (int) Sql::scalar($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't1'"));
    }

    public function testChangedAppliedMigrationIsRefused(): void
    {
        $pdo = TestDatabase::empty();
        $this->file('0001_one.sql', "CREATE TABLE t1 (id INT PRIMARY KEY) ENGINE=InnoDB;\n");
        (new Migrator($pdo, $this->dir, 'test'))->migrate(new UnavailableBackupGuard());
        $this->file('0001_one.sql', "CREATE TABLE t1 (id BIGINT PRIMARY KEY) ENGINE=InnoDB;\n");

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('checksum mismatch');
        (new Migrator($pdo, $this->dir, 'test'))->migrate(new RecordingBackupGuard());
    }

    public function testFailedMigrationIsLeftIncompleteAndBlocksFurtherRuns(): void
    {
        $pdo = TestDatabase::empty();
        $this->file('0001_one.sql', "CREATE TABLE t1 (id INT PRIMARY KEY) ENGINE=InnoDB;\nINSERT INTO no_such_table VALUES (1);\n");
        $migrator = new Migrator($pdo, $this->dir, 'test');

        try {
            $migrator->migrate(new UnavailableBackupGuard());
            self::fail('broken migration succeeded');
        } catch (MigrationError $e) {
            self::assertStringContainsString('left incomplete', $e->getMessage());
            self::assertStringContainsString(Migrator::EMPTY_DATABASE, $e->getMessage());
        }
        self::assertSame('incomplete', $migrator->status()[0]['state']);

        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('never finished');
        $migrator->migrate(new RecordingBackupGuard());
    }

    public function testNumberingGapsAndStrayFilesAreRefused(): void
    {
        $pdo = TestDatabase::empty();
        $this->file('0001_one.sql', "SELECT 1;\n");
        $this->file('0003_three.sql', "SELECT 1;\n");
        try {
            (new Migrator($pdo, $this->dir, 'test'))->migrate(new UnavailableBackupGuard());
            self::fail('gap accepted');
        } catch (MigrationError $e) {
            self::assertStringContainsString('gap', $e->getMessage());
        }

        unlink("{$this->dir}/0003_three.sql");
        $this->file('notes.txt', 'x');
        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('Unexpected file');
        (new Migrator($pdo, $this->dir, 'test'))->migrate(new UnavailableBackupGuard());
    }

    public function testOnlyOneRunAtATime(): void
    {
        $pdo = TestDatabase::empty();
        $holder = TestDatabase::connect();
        self::assertSame('1', (string) Sql::scalar($holder, "SELECT GET_LOCK('nova_migrate', 0)"));
        $this->file('0001_one.sql', "SELECT 1;\n");

        try {
            $this->expectException(MigrationError::class);
            $this->expectExceptionMessage('in progress');
            (new Migrator($pdo, $this->dir, 'test'))->migrate(new UnavailableBackupGuard());
        } finally {
            $holder->query("SELECT RELEASE_LOCK('nova_migrate')");
        }
    }
}
