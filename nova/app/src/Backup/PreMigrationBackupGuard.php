<?php

declare(strict_types=1);

namespace Nova\Backup;

use Nova\Migration\BackupGuard;
use Nova\Migration\MigrationError;
use PDO;

/**
 * The real pre-migration backup (05 §3, D23): before the first change to a
 * non-empty database, a verified `pre_migration` set is written, and its id
 * is recorded with every migration of the run. If the backup cannot be made
 * or does not verify, the migration is refused. This replaces the "no
 * mechanism" guard that Phase 3 used until this step.
 */
final readonly class PreMigrationBackupGuard implements BackupGuard
{
    public function __construct(private BackupService $backups) {}

    public function ensureBackup(PDO $pdo, string $reason): string
    {
        try {
            $set = $this->backups->run('pre_migration', 'cli', false);
            $problems = $this->backups->verify($set['id']);
        } catch (\Throwable $e) {
            throw new MigrationError("A backup is required {$reason} and could not be made: {$e->getMessage()}", 0, $e);
        }
        if ($problems !== []) {
            throw new MigrationError("The backup made {$reason} did not verify ({$problems[0]}). Migration refused.");
        }

        return $set['id'];
    }
}
