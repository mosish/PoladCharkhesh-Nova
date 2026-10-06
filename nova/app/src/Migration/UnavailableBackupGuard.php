<?php

declare(strict_types=1);

namespace Nova\Migration;

use PDO;

/**
 * Used wherever no backup mechanism is wired in: a non-empty database is
 * never migrated without a backup, so this always refuses.
 */
final class UnavailableBackupGuard implements BackupGuard
{
    public function ensureBackup(PDO $pdo, string $reason): string
    {
        throw new MigrationError("A backup is required {$reason}, but no backup mechanism is available. Migration refused.");
    }
}
