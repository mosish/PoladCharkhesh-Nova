<?php

declare(strict_types=1);

namespace Nova\Core;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class Files
{
    /** Deletes a directory tree (never follows symlinks out of it). No-op when absent. */
    public static function removeTree(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            \assert($item instanceof SplFileInfo);
            $ok = $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            if (!$ok) {
                throw new RuntimeException('Cannot remove ' . $item->getPathname());
            }
        }
        rmdir($dir);
    }

    /**
     * Writes a file atomically and durably: a temp file in the same directory,
     * flushed to disk, renamed over the target, then the directory flushed so
     * the rename itself survives a power cut.
     */
    public static function writeAtomic(string $path, string $content): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $handle = @fopen($tmp, 'xb');
        $ok = $handle !== false && fwrite($handle, $content) === \strlen($content) && fflush($handle) && fsync($handle);
        if ($handle !== false) {
            fclose($handle);
        }
        if (!$ok || !rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException("Cannot write {$path}");
        }
        self::syncDirectory(\dirname($path));
    }

    /** Flushes a directory's entries (creations, renames, deletions) to disk where the platform allows it. */
    public static function syncDirectory(string $dir): void
    {
        $handle = @fopen($dir, 'r');
        if ($handle !== false) {
            @fsync($handle);
            fclose($handle);
        }
    }

    /**
     * The canonical absolute form of a path that may not exist yet. The
     * longest existing prefix is resolved by the operating system (symlinks
     * and ".." included, in the order the OS applies them); the missing rest
     * cannot contain symlinks, so its "." and ".." are applied textually.
     */
    public static function resolve(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, '/')) {
            $path = str_replace('\\', '/', (string) getcwd()) . '/' . $path;
        }
        $parts = array_values(array_filter(explode('/', $path), static fn(string $p): bool => $p !== '' && $p !== '.'));
        $missing = [];
        while (($real = realpath('/' . implode('/', $parts))) === false) {
            $missing[] = (string) array_pop($parts);
        }
        $resolved = $real === '/' ? [] : explode('/', ltrim($real, '/'));
        foreach (array_reverse($missing) as $part) {
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        return '/' . implode('/', $resolved);
    }

    /** Whether `$path` is `$dir` itself or anywhere inside it, after resolving both. */
    public static function isInside(string $path, string $dir): bool
    {
        $path = self::resolve($path);
        $dir = self::resolve($dir);

        return $path === $dir || str_starts_with($path, rtrim($dir, '/') . '/');
    }
}
