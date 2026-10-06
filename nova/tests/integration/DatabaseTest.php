<?php

declare(strict_types=1);

namespace Nova\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The local MariaDB matches the database strategy in 05 §3. Runs against the
 * separate nova_test database, never the development data.
 */
final class DatabaseTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            \sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', Env::get('NOVA_DB_HOST'), Env::get('NOVA_DB_TEST_NAME')),
            Env::get('NOVA_DB_USER'),
            Env::get('NOVA_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        // What the application will set on every connection (05 §3).
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', time_zone = '+00:00', collation_connection = 'utf8mb4_unicode_520_ci'");
    }

    private function scalar(string $sql): string
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();
        self::assertIsScalar($value);

        return (string) $value;
    }

    public function testServerIsMariaDb114Lts(): void
    {
        $version = $this->scalar('SELECT VERSION()');
        self::assertStringStartsWith('11.4.', $version);
        self::assertStringContainsString('MariaDB', $version);
    }

    public function testTestDatabaseDefaultsToUtf8mb4Unicode520(): void
    {
        $statement = $this->pdo->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $statement->execute([Env::get('NOVA_DB_TEST_NAME')]);
        self::assertSame(['utf8mb4', 'utf8mb4_unicode_520_ci'], $statement->fetch(PDO::FETCH_NUM));
    }

    public function testInnoDbIsTheDefaultEngine(): void
    {
        self::assertSame('InnoDB', $this->scalar('SELECT @@default_storage_engine'));
    }

    public function testStrictModeRejectsOutOfRangeValues(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE strict_probe (v TINYINT NOT NULL) ENGINE=InnoDB');

        try {
            $this->pdo->exec('INSERT INTO strict_probe (v) VALUES (1000)');
            self::fail('Strict mode accepted an out-of-range value');
        } catch (PDOException $e) {
            self::assertSame('22003', $e->getCode());
        }
    }

    public function testPersianAndFourByteTextRoundTrips(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE text_probe (t VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci');
        $text = 'سلام ۱۲۳ ي ك 😀';
        $this->pdo->prepare('INSERT INTO text_probe (t) VALUES (?)')->execute([$text]);
        self::assertSame($text, $this->scalar('SELECT t FROM text_probe'));
    }

    public function testConsistentSnapshotAndNamedLockAreAvailable(): void
    {
        // The Publish Engine reads one consistent snapshot under a named lock (05 §3).
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        self::assertSame('1', $this->scalar("SELECT GET_LOCK('nova_test_lock', 0)"));
        self::assertSame('1', $this->scalar("SELECT RELEASE_LOCK('nova_test_lock')"));
        $this->pdo->exec('COMMIT');
    }
}
