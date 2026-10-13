<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Core\Files;
use RuntimeException;

/**
 * Strategy S (05 §8): releases live in `_site/releases/<id>/`, and
 * `_site/current` is a symlink to the live one, switched by renaming a new
 * link over it (rename(2) replaces it atomically). Requires that PHP can
 * create symlinks and the web server follows them (checked by the probe).
 */
final readonly class SymlinkSwitcher implements ReleaseSwitcher
{
    public function __construct(private string $siteDir) {}

    public function name(): string
    {
        return 'symlink';
    }

    public function prepare(string $id): string
    {
        $dir = $this->dir($id);
        if (file_exists($dir)) {
            throw new RuntimeException("Release {$id} already exists.");
        }
        if (!mkdir($dir, 0775, true)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        return $dir;
    }

    public function activate(string $id): void
    {
        if (!is_dir($this->dir($id))) {
            throw new RuntimeException("Release {$id} is not on disk.");
        }
        $tmp = $this->siteDir . '/current.tmp-' . bin2hex(random_bytes(4));
        if (!symlink('releases/' . $id, $tmp)) {
            throw new RuntimeException('Cannot create a symlink (strategy S unavailable).');
        }
        if (!rename($tmp, $this->siteDir . '/current')) {
            @unlink($tmp);

            throw new RuntimeException('Cannot switch the current release.');
        }
        Files::syncDirectory($this->siteDir);
    }

    /**
     * The live release, read from the link Nova writes: always the relative
     * target `releases/<id>`, so it stays valid wherever the tree is mounted.
     * Anything else (an absolute or dangling target, as left by a tool outside
     * Nova re-creating the link, or a plain file or folder) is refused rather
     * than read as "nothing is live", which would misdirect rollback,
     * recovery and pruning.
     */
    public function current(): ?string
    {
        $link = $this->siteDir . '/current';
        if (!is_link($link) && !file_exists($link)) {
            return null;
        }
        $target = is_link($link) ? readlink($link) : false;
        if (\is_string($target) && preg_match('#^releases/(R-[0-9]{8}-[0-9]{6}-[0-9a-f]{4})$#', $target, $m) === 1 && is_dir($this->dir($m[1]))) {
            return $m[1];
        }

        throw new RuntimeException(
            '_site/current is not the relative release link Nova writes (found: ' . (\is_string($target) ? "a link to \"{$target}\"" : 'not a symlink') . '). '
            . 'Something outside Nova changed it. Replace it with a relative link to a release on disk (ln -sfn releases/<id> _site/current) or remove it and publish again.',
        );
    }

    public function has(string $id): bool
    {
        return is_dir($this->dir($id));
    }

    public function discard(string $id): void
    {
        if ($this->current() === $id) {
            throw new RuntimeException("Release {$id} is live and cannot be removed.");
        }
        Files::removeTree($this->dir($id));
    }

    public function waitBefore(string $id): int
    {
        return 0;
    }

    public function releaseDir(string $id): string
    {
        return $this->dir($id);
    }

    private function dir(string $id): string
    {
        if (preg_match('/^R-[0-9]{8}-[0-9]{6}-[0-9a-f]{4}$/', $id) !== 1) {
            throw new RuntimeException("Invalid release id {$id}");
        }

        return $this->siteDir . '/releases/' . $id;
    }
}
