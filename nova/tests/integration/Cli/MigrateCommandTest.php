<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Cli;

use Nova\Cli\Application;
use Nova\Cli\Output;
use Nova\Core\Config;
use Nova\Core\Environment;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Dev\NoBackupGuard;
use Nova\Migration\MigrationError;
use Nova\Tests\Integration\TestDatabase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The development-only --no-backup bypass (owner decision 2026-10-06): visible,
 * recorded, and impossible outside development or outside the command line.
 */
final class MigrateCommandTest extends TestCase
{
    /** @param array<string, mixed> $values */
    private function app(Environment $environment, Output $output, string $sapi = 'cli', array $values = []): Application
    {
        return new Application(new Config($environment, TestDatabase::config(), $values), Paths::fromHere(), $output, $sapi);
    }

    private function nonEmptyDatabase(): PDO
    {
        $pdo = TestDatabase::empty();
        $pdo->exec('CREATE TABLE pre_existing (id INT PRIMARY KEY) ENGINE=InnoDB');

        return $pdo;
    }

    /** @return iterable<string, array{Environment}> */
    public static function nonDevelopment(): iterable
    {
        yield 'production' => [Environment::Production];
        yield 'staging' => [Environment::Staging];
        yield 'unset (fails closed to production)' => [Environment::fromValue(null)];
        yield 'unknown value (fails closed to production)' => [Environment::fromValue('dev')];
    }

    #[DataProvider('nonDevelopment')]
    public function testNoBackupIsRefusedOutsideDevelopment(Environment $environment): void
    {
        $pdo = $this->nonEmptyDatabase();
        $output = new Output(false);

        self::assertSame(2, $this->app($environment, $output)->run(['nova', 'migrate', '--no-backup']));
        self::assertStringContainsString('--no-backup is refused', implode("\n", $output->errors));
        self::assertSame(0, (int) Sql::scalar($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product'"));
    }

    public function testNoBackupIsRefusedOutsideTheCommandLine(): void
    {
        $this->expectException(MigrationError::class);
        $this->expectExceptionMessage('only from the command line');
        new NoBackupGuard(Environment::Development, static function (string $line): void {}, 'apache2handler');
    }

    public function testANonEmptyDatabaseIsBackedUpAndVerifiedBeforeItIsMigrated(): void
    {
        $pdo = $this->nonEmptyDatabase();
        $tmp = sys_get_temp_dir() . '/nova-migrate-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/public', 0775, true);
        $output = new Output(false);
        try {
            self::assertSame(0, $this->app(Environment::Development, $output, 'cli', ['paths' => ['public' => $tmp . '/public', 'storage' => $tmp . '/storage']])->run(['nova', 'migrate']), implode("\n", $output->errors));
            $sets = glob($tmp . '/storage/backups/sets/B-*.json') ?: [];
            self::assertCount(1, $sets, 'a pre_migration set was written first');
            $id = basename($sets[0], '.json');
            self::assertSame($id, Sql::scalar($pdo, 'SELECT backup_ref FROM schema_migrations WHERE version = 1'), 'and recorded with the migration');
            $manifest = json_decode((string) file_get_contents($sets[0]), true);
            self::assertIsArray($manifest);
            self::assertSame('pre_migration', $manifest['trigger'] ?? null);
        } finally {
            \Nova\Core\Files::removeTree($tmp);
        }
    }

    public function testWhenTheBackupCannotBeMadeANonEmptyDatabaseIsNeverMigrated(): void
    {
        $pdo = $this->nonEmptyDatabase();
        $blocker = sys_get_temp_dir() . '/nova-migrate-file-' . bin2hex(random_bytes(4));
        file_put_contents($blocker, 'a file where the backup folder should be');
        $output = new Output(false);
        try {
            // The backup folder would have to be created inside a plain file: impossible.
            self::assertSame(2, $this->app(Environment::Development, $output, 'cli', ['backup' => ['dir' => $blocker . '/backups']])->run(['nova', 'migrate']));
            self::assertStringContainsString('backup is required', implode("\n", $output->errors));
            self::assertSame(0, (int) Sql::scalar($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product'"), 'nothing was migrated');
        } finally {
            unlink($blocker);
        }
    }

    public function testDevelopmentBypassIsVisibleAndRecorded(): void
    {
        $pdo = $this->nonEmptyDatabase();
        $output = new Output(false);

        self::assertSame(0, $this->app(Environment::Development, $output)->run(['nova', 'migrate', '--no-backup']));
        self::assertStringContainsString('WARNING: migrating WITHOUT a backup', implode("\n", $output->errors));
        self::assertSame(NoBackupGuard::REFERENCE, Sql::scalar($pdo, 'SELECT backup_ref FROM schema_migrations WHERE version = 1'));
        self::assertSame('migration.no_backup', Sql::scalar($pdo, 'SELECT action FROM audit_event'));
    }

    #[DataProvider('nonDevelopment')]
    public function testDevResetIsRefusedOutsideDevelopment(Environment $environment): void
    {
        $pdo = $this->nonEmptyDatabase();
        $output = new Output(false);

        self::assertSame(2, $this->app($environment, $output)->run(['nova', 'dev:reset']));
        self::assertSame(1, (int) Sql::scalar($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pre_existing'"), 'nothing was dropped');
    }

    public function testDevResetIsRefusedOutsideTheCommandLine(): void
    {
        $this->nonEmptyDatabase();
        self::assertSame(2, $this->app(Environment::Development, new Output(false), 'apache2handler')->run(['nova', 'dev:reset']));
    }

    public function testStatusCommandListsMigrations(): void
    {
        TestDatabase::migrated();
        $output = new Output(false);
        self::assertSame(0, $this->app(Environment::Production, $output)->run(['nova', 'migrate:status']));
        self::assertMatchesRegularExpression('/^0001 catalogue_core\s+applied$/', $output->lines[0]);
    }
}
