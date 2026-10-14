<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Designation\DesignationIndex;
use Nova\Publish\ReleaseSwitcher;
use RuntimeException;
use Throwable;

/**
 * The designation index of the release that is live now (E-21): the same
 * index the visitor's page embedded, read from one complete release.
 *
 * Consistency: the live release id is read once, its files are read from
 * that release's own directory (never through the `current` pointer, which a
 * publish may move meanwhile), and each file must match the release manifest
 * read from the same directory. A release built before step 10 has no
 * `designation-index.json`; its index is then taken from the embedded copy
 * in `fa/index.html`, verified the same way.
 */
final readonly class LiveIndex
{
    public const string FILE = 'designation-index.json';

    public function __construct(private ReleaseSwitcher $switcher) {}

    /**
     * @return array{release: string, index: DesignationIndex}|null null when no release is live
     *
     * @throws RuntimeException when the live release cannot be read or verified
     */
    public function load(): ?array
    {
        $release = $this->switcher->current();
        if ($release === null) {
            return null;
        }
        $dir = $this->switcher->releaseDir($release);
        $manifest = json_decode((string) @file_get_contents("{$dir}/manifest.json"), true);
        $files = \is_array($manifest) && \is_array($manifest['files'] ?? null) ? $manifest['files'] : null;
        if ($files === null) {
            throw new RuntimeException("The manifest of the live release {$release} is missing or unreadable.");
        }

        if (\is_array($files[self::FILE] ?? null)) {
            $data = json_decode(self::verified($dir, self::FILE, $files), true);
            if (!\is_array($data) || ($data['release'] ?? null) !== $release) {
                throw new RuntimeException("The designation index of {$release} names another release.");
            }
            $index = $data['index'] ?? null;
        } else {
            $html = self::verified($dir, 'fa/index.html', $files);
            if (preg_match('#<script type="application/json" id="dindex">(.*?)</script>#s', $html, $m) !== 1) {
                throw new RuntimeException("The live release {$release} embeds no designation index.");
            }
            $index = json_decode($m[1], true);
        }

        try {
            return ['release' => $release, 'index' => DesignationIndex::fromArray($index)];
        } catch (Throwable $e) {
            throw new RuntimeException("The designation index of {$release} is invalid: {$e->getMessage()}", 0, $e);
        }
    }

    /** @param array<array-key, mixed> $files the manifest's file list */
    private static function verified(string $dir, string $file, array $files): string
    {
        $content = @file_get_contents("{$dir}/{$file}");
        $expected = \is_array($files[$file] ?? null) ? ($files[$file]['sha256'] ?? null) : null;
        if ($content === false || !\is_string($expected) || !hash_equals($expected, hash('sha256', $content))) {
            throw new RuntimeException("{$file} of the live release is missing or does not match its manifest.");
        }

        return $content;
    }
}
