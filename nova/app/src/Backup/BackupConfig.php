<?php

declare(strict_types=1);

namespace Nova\Backup;

use Nova\Core\Config;

/**
 * Backup settings (`backup.*` in the server configuration):
 * - `dir`: where backups live; default `<storage>/backups`. It must be outside the document root.
 * - `keep`: retention (D13): daily, weekly and monthly sets to keep.
 * - `fallback`: when no reliable scheduler exists, the first Admin request of the day makes the backup (05 §18).
 * - `overdue_hours`: how old the last successful backup may be before the dashboard warns.
 */
final readonly class BackupConfig
{
    public function __construct(
        public int $daily = 7,
        public int $weekly = 4,
        public int $monthly = 3,
        public int $keepSafety = 5,
        public bool $fallback = false,
        public int $overdueHours = 26,
        public ?string $dir = null,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $b = $config->get('backup');
        $b = \is_array($b) ? $b : [];
        $keep = \is_array($b['keep'] ?? null) ? $b['keep'] : [];
        $int = static fn(mixed $v, int $default): int => is_numeric($v) && (int) $v >= 0 ? (int) $v : $default;

        return new self(
            $int($keep['daily'] ?? null, 7),
            $int($keep['weekly'] ?? null, 4),
            $int($keep['monthly'] ?? null, 3),
            $int($keep['safety'] ?? null, 5),
            ($b['fallback'] ?? false) === true,
            max(1, $int($b['overdue_hours'] ?? null, 26)),
            \is_string($b['dir'] ?? null) && $b['dir'] !== '' ? rtrim($b['dir'], '/') : null,
        );
    }
}
