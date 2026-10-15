<?php

declare(strict_types=1);

namespace Nova\Backup;

use RuntimeException;

/**
 * A refused or failed backup or restore. `$reason` is stable:
 * invalid_target, not_empty, wrong_target, interrupted, corrupt, incomplete,
 * traversal, schema_newer, locked, not_found, conflict, io, passphrase.
 */
final class BackupError extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
