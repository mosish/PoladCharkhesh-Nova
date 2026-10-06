<?php

declare(strict_types=1);

namespace Nova\Tests\Integration;

use Nova\Core\Database;
use Nova\Core\DatabaseConfig;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Migration\Migrator;
use Nova\Migration\UnavailableBackupGuard;
use PDO;

/** The nova_test database: emptied and migrated from scratch for each test that needs it. */
final class TestDatabase
{
    public static function config(): DatabaseConfig
    {
        return new DatabaseConfig(Env::get('NOVA_DB_HOST'), 3306, Env::get('NOVA_DB_TEST_NAME'), Env::get('NOVA_DB_USER'), Env::get('NOVA_DB_PASSWORD'));
    }

    public static function connect(): PDO
    {
        return Database::connect(self::config());
    }

    public static function empty(): PDO
    {
        $pdo = self::connect();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (Sql::column($pdo, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        return $pdo;
    }

    public static function migrated(): PDO
    {
        $pdo = self::empty();
        (new Migrator($pdo, Paths::fromHere()->migrations(), 'test'))->migrate(new UnavailableBackupGuard());

        return $pdo;
    }

    /** @param array<string, scalar|null> $row */
    public static function insert(PDO $pdo, string $table, array $row): int
    {
        $columns = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, \count($row), '?'));
        $pdo->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$marks})")->execute(array_values($row));

        return (int) $pdo->lastInsertId();
    }
}
