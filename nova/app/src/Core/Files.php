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

    /** Writes a file atomically (temp file in the same directory, then rename). */
    public static function writeAtomic(string $path, string $content): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false || !rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException("Cannot write {$path}");
        }
    }
}
