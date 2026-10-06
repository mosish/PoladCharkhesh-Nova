<?php

declare(strict_types=1);

namespace Nova\Migration;

use PDO;

/**
 * Makes sure a restorable backup exists before a migration changes a
 * non-empty database (05 §3, D23). Returns a reference recorded with every
 * migration it covers.
 */
interface BackupGuard
{
    /** @throws MigrationError when no backup could be made */
    public function ensureBackup(PDO $pdo, string $reason): string;
}
