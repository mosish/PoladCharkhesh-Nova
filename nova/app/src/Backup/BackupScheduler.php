<?php

declare(strict_types=1);

namespace Nova\Backup;

use Nova\Core\Sql;
use Nova\Publish\Clock;
use PDO;
use Throwable;

/**
 * When a scheduler is not available (05 §18): the scheduler (cron running
 * `app/bin/nova backup:run`) is preferred and is used whenever the host has
 * one. Only when `backup.fallback` is switched on does the first signed-in
 * Admin request of the day make the backup instead, and only when no
 * successful backup is newer than 24 hours, so a working scheduler is never
 * duplicated. A failed fallback is not retried for an hour, so a broken
 * backup cannot slow every request. Other entry points that need no
 * scheduler: the Owner's "back up now" in the Admin, and the command line.
 */
final readonly class BackupScheduler
{
    public const int DAY = 86_400;
    public const int RETRY_AFTER_FAILURE = 3_600;

    public function __construct(
        private PDO $pdo,
        private BackupService $backups,
        private BackupConfig $config,
        private Clock $clock,
    ) {}

    public function fallbackDue(): bool
    {
        if (!$this->config->fallback) {
            return false;
        }
        $last = $this->backups->lastSuccessful();
        if ($last !== null && $this->clock->now() - $last['at'] < self::DAY) {
            return false;
        }
        $failed = Sql::scalar($this->pdo, "SELECT MAX(occurred_at) FROM audit_event WHERE action = 'backup.failed'");

        return !(\is_string($failed) && $this->clock->now() - (int) strtotime($failed . ' UTC') < self::RETRY_AFTER_FAILURE);
    }

    /** Runs the fallback backup when due; never throws (the request goes on). @return string|null the new set's id */
    public function runFallbackIfDue(): ?string
    {
        if (!$this->fallbackDue()) {
            return null;
        }
        try {
            return $this->backups->run('fallback', 'admin')['id'];
        } catch (Throwable) {
            return null; // already audited by the backup itself
        }
    }
}
