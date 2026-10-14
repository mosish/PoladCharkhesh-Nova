<?php

declare(strict_types=1);

namespace Nova\Media;

use Nova\Core\Files;
use RuntimeException;

/**
 * Where media files live (05 §12): `public_html/media/<year>/<sha256>.<ext>`
 * (derivatives `<sha256>-<width>.webp`), outside the releases, so a release
 * and its rollbacks keep working after a file is replaced or trashed. Names
 * are content hashes, so a name can never be guessed from a title and the
 * same bytes are stored once. PHP is disabled and listing is off in
 * `media/.htaccess` (`SvgHeaders` documents the headers).
 */
final readonly class MediaStorage
{
    public const int RETENTION_DAYS = 30;

    public function __construct(private string $publicDir) {}

    public function root(): string
    {
        return $this->publicDir . '/media';
    }

    public function path(int $year, string $sha, string $ext, ?int $width = null): string
    {
        self::assertName($sha, $ext);

        return $this->root() . "/{$year}/" . $sha . ($width === null ? '' : "-{$width}") . ".{$ext}";
    }

    public function url(int $year, string $sha, string $ext, ?int $width = null): string
    {
        self::assertName($sha, $ext);

        return "/media/{$year}/" . $sha . ($width === null ? '' : "-{$width}") . ".{$ext}";
    }

    /** @param array<int, string> $derivatives width => WebP bytes */
    public function store(int $year, string $sha, string $ext, string $bytes, array $derivatives = []): void
    {
        $dir = $this->root() . "/{$year}";
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("The media folder {$dir} cannot be created.");
        }
        Files::writeAtomic($this->path($year, $sha, $ext), $bytes);
        foreach ($derivatives as $width => $data) {
            Files::writeAtomic($this->path($year, $sha, 'webp', $width), $data);
        }
    }

    /** Marks a file as recently superseded so the 30-day sweep counts from now. */
    public function touch(int $year, string $sha, string $ext, int $time, ?int $width = null): void
    {
        $path = $this->path($year, $sha, $ext, $width);
        if (is_file($path)) {
            @touch($path, $time);
        }
    }

    /**
     * Deletes files no record names, once they are older than the retention
     * (a replaced file, a trashed item that was purged, an interrupted write).
     *
     * @param \Closure(string): bool $known whether a SHA-256 is still named by a record
     *
     * @return int files deleted
     */
    public function sweep(\Closure $known, int $now, int $retentionDays = self::RETENTION_DAYS): int
    {
        $deleted = 0;
        foreach (glob($this->root() . '/[0-9][0-9][0-9][0-9]/*') ?: [] as $file) {
            if (preg_match('/^([0-9a-f]{64})(-[0-9]+)?\.(jpg|png|webp|svg|pdf)$/', basename($file), $m) !== 1 || $known($m[1])) {
                continue;
            }
            if (is_file($file) && $now - (int) filemtime($file) > $retentionDays * 86_400 && @unlink($file)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    private static function assertName(string $sha, string $ext): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1 || preg_match('/^(jpg|png|webp|svg|pdf)$/', $ext) !== 1) {
            throw new RuntimeException('Invalid media file name.');
        }
    }
}
