<?php

declare(strict_types=1);

namespace Nova\Media;

use Nova\Core\Sql;
use Nova\Publish\ReleaseSwitcher;
use PDO;

/**
 * The media files that retained releases still link to (05 §8, §12). A
 * release is static HTML and JSON that name `/media/<year>/<sha256>.<ext>`;
 * rolling back to one must not find those files gone. The purge keeps every
 * file any retained release (live, superseded or rolled back, not pruned)
 * names, however old, by reading the releases themselves rather than
 * trusting the database alone.
 */
final class ReleaseMedia
{
    private const string PATTERN = '#/media/[0-9]{4}/([0-9a-f]{64})(?:-[0-9]+)?\.(?:jpg|png|webp|svg|pdf)#';

    /** @return array<string, true> SHA-256 => true */
    public static function referenced(PDO $pdo, ReleaseSwitcher $switcher): array
    {
        $shas = [];
        foreach (Sql::column($pdo, "SELECT release_id FROM publish_record WHERE status IN ('live', 'superseded', 'rolled_back')") as $id) {
            $id = (string) $id;
            if ($switcher->has($id) || $switcher->current() === $id) {
                self::scan($switcher->releaseDir($id), $shas);
            }
        }

        return $shas;
    }

    /** @param array<string, true> $shas */
    public static function scan(string $dir, array &$shas): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || preg_match('/\.(html|json|xml|txt)$/', $file->getFilename()) !== 1) {
                continue;
            }
            if (preg_match_all(self::PATTERN, (string) file_get_contents($file->getPathname()), $m) > 0) {
                foreach ($m[1] as $sha) {
                    $shas[$sha] = true;
                }
            }
        }
    }
}
