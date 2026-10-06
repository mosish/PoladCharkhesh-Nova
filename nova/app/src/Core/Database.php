<?php

declare(strict_types=1);

namespace Nova\Core;

use PDO;

/**
 * Opens connections exactly as 05 §3 requires: exceptions, native prepared
 * statements, utf8mb4, and on every connection a strict sql_mode, UTC and the
 * project collation, whatever the server defaults are.
 */
final class Database
{
    public const string SQL_MODE = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ZERO_DATE,NO_ZERO_IN_DATE,NO_ENGINE_SUBSTITUTION';

    public static function connect(DatabaseConfig $config): PDO
    {
        $pdo = new PDO(
            \sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->host, $config->port, $config->name),
            $config->user,
            $config->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => false,
            ],
        );
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_520_ci");
        $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "', time_zone = '+00:00'");

        return $pdo;
    }
}
