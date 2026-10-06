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
    private function app(Environment $environment, Output $output, string $sapi = 'cli'): Application
    {
        return new Application(new Config($environment, TestDatabase::config()), Paths::fromHere(), $output, $sapi);
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

    public function testWithoutTheBypassANonEmptyDatabaseIsNeverMigrated(): void
    {
        $this->nonEmptyDatabase();
        $output = new Output(false);

        self::assertSame(2, $this->app(Environment::Development, $output)->run(['nova', 'migrate']));
        self::assertStringContainsString('backup is required', implode("\n", $output->errors));
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

    public function testStatusCommandListsMigrations(): void
    {
        TestDatabase::migrated();
        $output = new Output(false);
        self::assertSame(0, $this->app(Environment::Production, $output)->run(['nova', 'migrate:status']));
        self::assertMatchesRegularExpression('/^0001 catalogue_core\s+applied$/', $output->lines[0]);
    }
}
