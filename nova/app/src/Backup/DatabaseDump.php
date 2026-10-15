<?php

declare(strict_types=1);

namespace Nova\Backup;

use Nova\Core\Sql;
use PDO;
use RuntimeException;

/**
 * The PHP-written logical database dump (05 §18): schema and data of one
 * consistent snapshot, gzip-compressed, with a row count and a content
 * checksum for every table so a restore can prove it reproduced the source.
 *
 * - One `START TRANSACTION WITH CONSISTENT SNAPSHOT` covers every table, so
 *   the dump is a single point in time while the site keeps working.
 * - Statements are separated by a line holding only `-- ---`; values are
 *   quoted by the database driver, so no value can contain that line.
 * - Generated columns are not written (the server recomputes them).
 * - Rows not backed up, by design: `login_throttle` and `inquiry_attempt`
 *   (short-lived rows keyed by hashed visitor addresses). Their structure is
 *   kept, so a restored database is complete and the application works at
 *   once; they restore empty. Sessions are PHP files
 *   outside the database. Secrets are never in the database; the Owner's
 *   TOTP secrets and password hashes are, so the dump is private (0600, off
 *   the web root) and only leaves the server encrypted (`OffsiteCrypto`).
 * - A footer line proves the file is complete.
 */
final class DatabaseDump
{
    public const string FORMAT = 'nova-dump/1';
    public const string SEPARATOR = "\n-- ---\n";
    public const string FOOTER = '-- nova-dump end';
    /** @var list<string> */
    public const array EXCLUDED = ['login_throttle', 'inquiry_attempt'];
    /**
     * Append-only tables keep growing after a restore (the restore itself is audited there). Their backup fingerprint
     * covers the rows up to the last id at backup time, so a restored database still verifies later.
     *
     * @var list<string>
     */
    public const array APPEND_ONLY = ['audit_event'];
    private const int BATCH_ROWS = 100;
    private const int BATCH_BYTES = 1_000_000;

    /**
     * @param PDO $pdo a connection of its own: it holds the snapshot and streams rows unbuffered
     *
     * @return array{schema_version: int, tables: array<string, array{rows: int, checksum: string, max_id?: int}>, structure_only: list<string>, statements: int}
     */
    public static function write(PDO $pdo, string $gzPath): array
    {
        $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
        $part = $gzPath . '.part';
        $out = @gzopen($part, 'wb6');
        if ($out === false) {
            throw new RuntimeException("Cannot write {$part}");
        }
        @chmod($part, 0600);
        $statements = 0;
        $emit = static function (string $sql) use ($out, &$statements): void {
            if (gzwrite($out, $sql . self::SEPARATOR) === false) {
                throw new RuntimeException('Cannot write the dump.');
            }
            ++$statements;
        };

        try {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $emit('-- nova-dump/1');
            $emit('SET NAMES utf8mb4');
            $emit('SET FOREIGN_KEY_CHECKS = 0');
            $emit("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
            $tables = [];
            $structureOnly = [];
            foreach (self::tableNames($pdo, true) as $table) {
                $emit(self::createStatement($pdo, $table));
                if (\in_array($table, self::EXCLUDED, true)) {
                    $structureOnly[] = $table;
                } else {
                    $tables[$table] = self::rows($pdo, $table, $emit);
                }
            }
            $version = self::schemaVersion($pdo);
            gzwrite($out, self::FOOTER . ' statements=' . $statements . "\n");
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            gzclose($out);
            @unlink($part);

            throw $e;
        }
        gzclose($out);
        if (!rename($part, $gzPath)) {
            @unlink($part);

            throw new RuntimeException("Cannot write {$gzPath}");
        }
        @chmod($gzPath, 0600);

        return ['schema_version' => $version, 'tables' => $tables, 'structure_only' => $structureOnly, 'statements' => $statements];
    }

    /**
     * @param bool $withExcluded include the tables whose rows are not backed up (their structure is)
     *
     * @return list<string>
     */
    public static function tableNames(PDO $pdo, bool $withExcluded = false): array
    {
        return array_values(array_filter(
            array_map(strval(...), Sql::column($pdo, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")),
            static fn(string $t): bool => ($withExcluded || !\in_array($t, self::EXCLUDED, true)) && !str_starts_with($t, '_nova_'),
        ));
    }

    /**
     * Row count and content checksum of one table, in primary-key order: the
     * same computation at backup time and when verifying a restore.
     *
     * @param int|null $maxId for an append-only table: only rows up to this id
     *
     * @return array{rows: int, checksum: string}
     */
    public static function fingerprint(PDO $pdo, string $table, ?int $maxId = null): array
    {
        $count = 0;
        $hash = hash_init('sha256');
        $stmt = $pdo->query(self::select($pdo, $table, $maxId));
        if ($stmt === false) {
            throw new RuntimeException("Cannot read {$table}");
        }
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            hash_update($hash, json_encode(self::normalise($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            ++$count;
        }

        return ['rows' => $count, 'checksum' => hash_final($hash)];
    }

    /**
     * @param \Closure(string): void $emit
     *
     * @return array{rows: int, checksum: string, max_id?: int}
     */
    private static function rows(PDO $pdo, string $table, \Closure $emit): array
    {
        $columns = self::columns($pdo, $table);
        $idColumn = \in_array($table, self::APPEND_ONLY, true) ? array_search('id', $columns, true) : false;
        $maxId = 0;
        $head = 'INSERT INTO ' . self::id($table) . ' (' . implode(', ', array_map(self::id(...), $columns)) . ') VALUES ';
        $stmt = $pdo->query(self::select($pdo, $table));
        if ($stmt === false) {
            throw new RuntimeException("Cannot read {$table}");
        }
        $hash = hash_init('sha256');
        $batch = [];
        $bytes = 0;
        $count = 0;
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            $norm = self::normalise($row);
            hash_update($hash, json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            ++$count;
            if ($idColumn !== false) {
                $maxId = max($maxId, (int) $norm[$idColumn]);
            }
            $values = '(' . implode(', ', array_map(static fn(?string $v): string => $v === null ? 'NULL' : self::quote($v), $norm)) . ')';
            $batch[] = $values;
            $bytes += \strlen($values);
            if (\count($batch) >= self::BATCH_ROWS || $bytes >= self::BATCH_BYTES) {
                $emit($head . implode(",\n", $batch));
                $batch = [];
                $bytes = 0;
            }
        }
        $stmt->closeCursor();
        if ($batch !== []) {
            $emit($head . implode(",\n", $batch));
        }

        $result = ['rows' => $count, 'checksum' => hash_final($hash)];

        return $idColumn === false ? $result : $result + ['max_id' => $maxId];
    }

    /** MariaDB string-literal quoting, independent of the connection (so a restore needs no particular driver mode). */
    private static function quote(string $value): string
    {
        return "'" . strtr($value, ['\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z']) . "'";
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return list<?string>
     */
    private static function normalise(array $row): array
    {
        $out = [];
        foreach ($row as $v) {
            $out[] = match (true) {
                $v === null => null,
                \is_bool($v) => $v ? '1' : '0',
                \is_float($v) => rtrim(rtrim(\sprintf('%.17g', $v), '0'), '.') ?: '0',
                \is_scalar($v) => (string) $v,
                default => throw new RuntimeException('Unsupported column value.'),
            };
        }

        return $out;
    }

    private static function select(PDO $pdo, string $table, ?int $maxId = null): string
    {
        $columns = implode(', ', array_map(self::id(...), self::columns($pdo, $table)));
        $keys = array_map(strval(...), Sql::column($pdo, "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX", [$table]));

        return "SELECT {$columns} FROM " . self::id($table) . ($maxId === null ? '' : ' WHERE ' . self::id('id') . ' <= ' . $maxId) . ' ORDER BY ' . implode(', ', array_map(self::id(...), $keys === [] ? self::columns($pdo, $table) : $keys));
    }

    /** @return list<string> stored (not generated) columns, in table order */
    private static function columns(PDO $pdo, string $table): array
    {
        return array_map(strval(...), Sql::column($pdo, "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '') ORDER BY ORDINAL_POSITION", [$table]));
    }

    private static function createStatement(PDO $pdo, string $table): string
    {
        $row = Sql::row($pdo, 'SHOW CREATE TABLE ' . self::id($table));
        $sql = $row['Create Table'] ?? null;
        if (!\is_string($sql)) {
            throw new RuntimeException("Cannot read the definition of {$table}");
        }

        return $sql;
    }

    private static function schemaVersion(PDO $pdo): int
    {
        return Sql::int($pdo, 'SELECT COALESCE(MAX(version), 0) FROM schema_migrations WHERE finished_at IS NOT NULL');
    }

    public static function id(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
