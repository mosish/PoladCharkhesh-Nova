<?php

declare(strict_types=1);

namespace Nova\Backup;

use Nova\Core\Config;
use Nova\Core\Database;
use Nova\Core\Environment;
use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Migration\MigrationFile;
use Nova\Publish\PublishFactory;
use Nova\Publish\SystemClock;
use PDO;
use RuntimeException;
use Throwable;

/** Builds the backup and restore services from configuration (CLI and Admin). */
final class BackupFactory
{
    /**
     * The backup folder. Outside development it must be outside the document
     * root: it holds the database dump (password hashes, TOTP secrets,
     * inquiries), which must never be reachable over HTTP.
     */
    public static function paths(Config $config, Paths $paths): BackupPaths
    {
        [$public, $storage] = PublishFactory::locations($config, $paths);
        $dir = BackupConfig::fromConfig($config)->dir ?? $storage . '/backups';
        if ($config->environment !== Environment::Development && Files::isInside($dir, $public)) {
            throw new RuntimeException('backup.dir must be outside the document root (paths.public); it resolves inside it.');
        }

        return new BackupPaths($dir);
    }

    public static function service(Config $config, Paths $paths, PDO $pdo, string $appVersion): BackupService
    {
        [$public] = PublishFactory::locations($config, $paths);
        $switcher = PublishFactory::switcher($config, $paths);

        return new BackupService(
            $pdo,
            static fn(): PDO => Database::connect($config->database),
            self::paths($config, $paths),
            BackupConfig::fromConfig($config),
            $public . '/media',
            new SystemClock(),
            $appVersion,
            static function () use ($pdo, $switcher): array {
                $releases = [];
                try {
                    if (Sql::scalar($pdo, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'publish_record'") === null) {
                        return [];
                    }
                    foreach (Sql::rows($pdo, "SELECT release_id, status FROM publish_record WHERE status IN ('live', 'superseded', 'rolled_back') ORDER BY id") as $r) {
                        $id = (string) $r['release_id'];
                        $sum = null;
                        if ($switcher->has($id) || $switcher->current() === $id) {
                            $file = $switcher->releaseDir($id) . '/manifest.json';
                            $sum = is_file($file) ? (string) hash_file('sha256', $file) : null;
                        }
                        $releases[] = ['id' => $id, 'status' => (string) $r['status'], 'manifest_sha256' => $sum];
                    }
                } catch (Throwable) {
                    // A release list is context for a restore, never a reason to fail a backup.
                }

                return $releases;
            },
        );
    }

    public static function restore(Config $config, Paths $paths, PDO $pdo, BackupService $backups): RestoreService
    {
        $files = MigrationFile::scan($paths->migrations());

        return new RestoreService($pdo, $config->database, self::paths($config, $paths), $backups, $files === [] ? 0 : max(array_map(static fn(MigrationFile $f): int => $f->version, $files)));
    }
}
