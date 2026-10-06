<?php

declare(strict_types=1);

namespace Nova\Dev;

use Nova\Core\Environment;
use Nova\Migration\BackupGuard;
use Nova\Migration\MigrationError;
use PDO;

/**
 * DEVELOPMENT ONLY: `migrate --no-backup` before step 12's backups exist
 * (owner decision, 2026-10-06). Lives in app/dev, which is autoloaded only as
 * autoload-dev and is excluded from deploy packages, and it refuses to run
 * outside the development environment or outside the command line. The
 * bypass is announced and recorded as the migration's backup reference.
 */
final class NoBackupGuard implements BackupGuard
{
    public const string REFERENCE = 'NO-BACKUP (development)';

    /** @var \Closure(string): void */
    private \Closure $warn;

    /** @param callable(string): void $warn */
    public function __construct(Environment $environment, callable $warn, string $sapi = PHP_SAPI)
    {
        if ($environment !== Environment::Development) {
            throw new MigrationError("--no-backup is refused: the environment is {$environment->value}, not development.");
        }
        if ($sapi !== 'cli') {
            throw new MigrationError('--no-backup is available only from the command line.');
        }
        $this->warn = \Closure::fromCallable($warn);
    }

    public function ensureBackup(PDO $pdo, string $reason): string
    {
        ($this->warn)("WARNING: migrating WITHOUT a backup ({$reason}). Development-only bypass; recorded as \"" . self::REFERENCE . '".');

        return self::REFERENCE;
    }
}
