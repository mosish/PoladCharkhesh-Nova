<?php

declare(strict_types=1);

namespace Nova\Core;

use PDO;
use Throwable;

final class Transaction
{
    /**
     * Runs `$work` in one transaction (every multi-row save, 05 §3). Nested
     * calls join the outer transaction.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public static function run(PDO $pdo, callable $work): mixed
    {
        if ($pdo->inTransaction()) {
            return $work();
        }
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }
}
