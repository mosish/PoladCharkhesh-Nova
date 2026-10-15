<?php

declare(strict_types=1);

namespace Nova\Backup;

use Nova\Core\Files;
use Nova\Core\Sql;
use PDO;
use ZipArchive;

/**
 * Media and document backup from manifests (05 §12, §18).
 *
 * The manifest names every file the database records (images, their WebP
 * copies, SVG, PDFs; trashed items too, since the trash can be restored)
 * with its SHA-256 and size. Files are copied into a content-addressed store
 * shared by all backup sets, so each backup copies only what is new. A
 * restore copies files back from the store (or from a full archive) and
 * checks every hash.
 *
 * Paths are accepted only in one exact shape, `<year>/<sha256>[-<width>].<ext>`;
 * nothing else can be read from or written to, whatever a manifest claims.
 */
final class MediaFiles
{
    /** What an interrupted write leaves behind (`Files::writeAtomic`); a restore removes it. */
    public const string TEMP_PATTERN = '#^[0-9]{4}/[0-9a-f]{64}(?:-[0-9]+)?\.(?:jpg|png|webp|svg|pdf)\.tmp-[0-9a-f]{8}$#';
    public const string PATH_PATTERN = '#^[0-9]{4}/[0-9a-f]{64}(?:-[0-9]+)?\.(?:jpg|png|webp|svg|pdf)$#';

    /**
     * What the database says should exist.
     *
     * @return list<string> relative paths, sorted and unique
     */
    public static function expected(PDO $pdo): array
    {
        $paths = [];
        // A database from before the media migration (0009) has neither the media table nor documents with files.
        if (!self::hasTable($pdo, 'media') || !self::hasColumn($pdo, 'document', 'year')) {
            return [];
        }
        foreach (Sql::rows($pdo, 'SELECT year, sha256, ext, derivatives FROM media') as $m) {
            $base = (int) $m['year'] . '/' . $m['sha256'];
            $paths[] = $base . '.' . $m['ext'];
            foreach (array_filter(explode(',', (string) ($m['derivatives'] ?? ''))) as $w) {
                $paths[] = "{$base}-{$w}.webp";
            }
        }
        foreach (Sql::rows($pdo, 'SELECT year, sha256 FROM document') as $d) {
            $paths[] = ($d['year'] === null ? gmdate('Y') : (int) $d['year']) . '/' . $d['sha256'] . '.pdf';
        }
        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    private static function hasTable(PDO $pdo, string $table): bool
    {
        return Sql::scalar($pdo, 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) !== null;
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        return Sql::scalar($pdo, 'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]) !== null;
    }

    /**
     * Copies the files that exist into the store and describes them.
     *
     * @param list<string> $expected
     *
     * @return array{files: list<array{path: string, sha256: string, bytes: int}>, missing: list<string>, copied: int, bytes: int}
     */
    public static function snapshot(array $expected, string $mediaDir, string $store): array
    {
        $files = [];
        $missing = [];
        $copied = 0;
        $total = 0;
        foreach ($expected as $path) {
            self::assertPath($path);
            $src = $mediaDir . '/' . $path;
            if (!is_file($src)) {
                $missing[] = $path;
                continue;
            }
            $sha = (string) hash_file('sha256', $src);
            $bytes = (int) filesize($src);
            $dst = $store . '/' . $path;
            if (!is_file($dst) || hash_file('sha256', $dst) !== $sha) {
                self::copy($src, $dst);
                ++$copied;
            }
            $files[] = ['path' => $path, 'sha256' => $sha, 'bytes' => $bytes];
            $total += $bytes;
        }

        return ['files' => $files, 'missing' => $missing, 'copied' => $copied, 'bytes' => $total];
    }

    /**
     * Copies the manifest's files from the store into `$target`, verifying each.
     * Idempotent: a file already there with the right hash is left alone.
     *
     * @param list<array{path: string, sha256: string, bytes: int}> $files
     *
     * @return array{restored: int, unchanged: int}
     *
     * @throws BackupError traversal, wrong_target, incomplete, corrupt, conflict
     */
    public static function restore(array $files, string $store, string $target, string $protectedDir): array
    {
        self::assertTarget($target, $protectedDir);
        // Everything is checked before the first byte is written.
        foreach ($files as $f) {
            self::assertEntry($f);
            $src = $store . '/' . $f['path'];
            if (!is_file($src)) {
                throw new BackupError('incomplete', "The backup is missing {$f['path']}.");
            }
            if (hash_file('sha256', $src) !== $f['sha256']) {
                throw new BackupError('corrupt', "{$f['path']} in the backup does not match its checksum.");
            }
        }
        // Conflicts are found before the first write, so a refused restore changes nothing.
        $todo = [];
        $unchanged = 0;
        foreach ($files as $f) {
            $dst = $target . '/' . $f['path'];
            if (!is_file($dst)) {
                $todo[] = $f;
            } elseif (hash_file('sha256', $dst) === $f['sha256']) {
                ++$unchanged;
            } else {
                throw new BackupError('conflict', "{$f['path']} already exists in the target with different content.");
            }
        }
        // What an interrupted earlier restore left behind is removed.
        foreach (glob($target . '/[0-9][0-9][0-9][0-9]/*.tmp-*') ?: [] as $left) {
            if (preg_match(self::TEMP_PATTERN, substr($left, \strlen(rtrim($target, '/')) + 1)) === 1) {
                @unlink($left);
            }
        }
        foreach ($todo as $f) {
            self::copy($store . '/' . $f['path'], $target . '/' . $f['path']);
        }
        $restored = \count($todo);

        return ['restored' => $restored, 'unchanged' => $unchanged];
    }

    /**
     * A full archive (zip) of a manifest's files plus the manifest.
     *
     * @param list<array{path: string, sha256: string, bytes: int}> $files
     */
    public static function archive(array $files, string $store, string $zipPath): void
    {
        $zip = new ZipArchive();
        $part = $zipPath . '.part';
        if ($zip->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupError('io', 'The archive cannot be created.');
        }
        foreach ($files as $f) {
            self::assertEntry($f);
            if (!$zip->addFile($store . '/' . $f['path'], $f['path'])) {
                throw new BackupError('incomplete', "The backup is missing {$f['path']}.");
            }
        }
        $zip->addFromString('media-manifest.json', json_encode(['format' => 'nova-media/1', 'files' => $files], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!$zip->close() || !rename($part, $zipPath)) {
            @unlink($part);

            throw new BackupError('io', 'The archive cannot be written.');
        }
        @chmod($zipPath, 0600);
    }

    /**
     * Restores from a full archive. The archive's own manifest is checked
     * entry by entry before anything is extracted: only manifest paths of the
     * exact shape are read, each must be in the archive, and each must match.
     *
     * @return array{restored: int, unchanged: int}
     */
    public static function restoreArchive(string $zipPath, string $target, string $protectedDir, string $workDir): array
    {
        $zip = new ZipArchive();
        if (!is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new BackupError('not_found', 'The archive cannot be opened.');
        }
        try {
            $json = $zip->getFromName('media-manifest.json');
            $manifest = \is_string($json) ? json_decode($json, true) : null;
            if (!\is_array($manifest) || ($manifest['format'] ?? null) !== 'nova-media/1' || !\is_array($manifest['files'] ?? null)) {
                throw new BackupError('corrupt', 'The archive has no valid media manifest.');
            }
            /** @var list<array{path: string, sha256: string, bytes: int}> $files */
            $files = [];
            foreach ($manifest['files'] as $f) {
                if (!\is_array($f) || !\is_string($f['path'] ?? null) || !\is_string($f['sha256'] ?? null) || !\is_int($f['bytes'] ?? null)) {
                    throw new BackupError('corrupt', 'The archive manifest is malformed.');
                }
                $files[] = ['path' => $f['path'], 'sha256' => $f['sha256'], 'bytes' => $f['bytes']];
            }
            // Extract each manifest entry to a private work folder under the exact validated name, then restore from there.
            $stage = $workDir . '/stage-' . bin2hex(random_bytes(4));
            foreach ($files as $f) {
                self::assertEntry($f);
                $data = $zip->getFromName($f['path']);
                if (!\is_string($data)) {
                    throw new BackupError('incomplete', "The archive is missing {$f['path']}.");
                }
                if (!is_dir($stage . '/' . \dirname($f['path'])) && !@mkdir($stage . '/' . \dirname($f['path']), 0700, true)) {
                    throw new BackupError('io', 'Cannot stage the archive.');
                }
                file_put_contents($stage . '/' . $f['path'], $data);
            }
            try {
                return self::restore($files, $stage, $target, $protectedDir);
            } finally {
                Files::removeTree($stage);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * A restore target must be a media folder: absent, empty, or already holding only what a restore writes. It may not be (or lie inside)
     * the backup folder, or be the filesystem root.
     *
     * @throws BackupError wrong_target
     */
    public static function assertTarget(string $target, string $protectedDir): void
    {
        $real = Files::resolve($target);
        if ($real === '/' || substr_count($real, '/') < 2) {
            throw new BackupError('wrong_target', 'That is not a media folder.');
        }
        if (Files::isInside($real, $protectedDir) || Files::isInside($protectedDir, $real)) {
            throw new BackupError('wrong_target', 'The target overlaps the backup folder.');
        }
        if (is_file($real)) {
            throw new BackupError('wrong_target', 'The target is a file.');
        }
        if (!is_dir($real)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $rel = substr($item->getPathname(), \strlen($real) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($item->isLink()) {
                throw new BackupError('wrong_target', "The target holds a link ({$rel}).");
            }
            $ok = $rel === '.htaccess' || preg_match('#^[0-9]{4}$#', $rel) === 1 && $item->isDir()
                || preg_match(self::PATH_PATTERN, $rel) === 1 && $item->isFile()
                || preg_match(self::TEMP_PATTERN, $rel) === 1 && $item->isFile();
            if (!$ok) {
                throw new BackupError('wrong_target', "The target already holds something that is not media ({$rel}).");
            }
        }
    }

    public static function assertPath(string $path): void
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw new BackupError('traversal', 'A media path is not of the form <year>/<sha256>.<ext>.');
        }
    }

    /** @param array{path: string, sha256: string, bytes: int} $f */
    private static function assertEntry(array $f): void
    {
        self::assertPath($f['path']);
        if (preg_match('/^[0-9a-f]{64}$/', $f['sha256']) !== 1) {
            throw new BackupError('corrupt', 'A manifest checksum is malformed.');
        }
    }

    private static function copy(string $src, string $dst): void
    {
        $dir = \dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new BackupError('io', "Cannot create {$dir}.");
        }
        Files::writeAtomic($dst, (string) file_get_contents($src));
    }
}
