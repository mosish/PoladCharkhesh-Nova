<?php

declare(strict_types=1);

namespace Nova\Core;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Typed helpers over PDO: every query is a prepared statement with bound
 * parameters (05 §15), and results come back with precise types.
 */
final class Sql
{
    /** @param list<scalar|null> $params */
    public static function run(PDO $pdo, string $sql, array $params = []): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Statement could not be prepared.');
        }
        $statement->execute($params);

        return $statement;
    }

    /** @param list<scalar|null> $params */
    public static function scalar(PDO $pdo, string $sql, array $params = []): string|int|null
    {
        $value = self::run($pdo, $sql, $params)->fetchColumn();

        return \is_string($value) || \is_int($value) ? $value : null;
    }

    /** @param list<scalar|null> $params */
    public static function int(PDO $pdo, string $sql, array $params = []): int
    {
        return (int) self::scalar($pdo, $sql, $params);
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return array<string, scalar|null>|null
     */
    public static function row(PDO $pdo, string $sql, array $params = []): ?array
    {
        $row = self::run($pdo, $sql, $params)->fetch(PDO::FETCH_ASSOC);

        /** @var array<string, scalar|null>|false $row */
        return $row === false ? null : $row;
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<array<string, scalar|null>>
     */
    public static function rows(PDO $pdo, string $sql, array $params = []): array
    {
        /** @var list<array<string, scalar|null>> */
        return self::run($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return array<array-key, scalar|null> first column => second column
     */
    public static function pairs(PDO $pdo, string $sql, array $params = []): array
    {
        /** @var array<array-key, scalar|null> */
        return self::run($pdo, $sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<scalar|null>
     */
    public static function column(PDO $pdo, string $sql, array $params = []): array
    {
        /** @var list<scalar|null> */
        return self::run($pdo, $sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }
}
