<?php

declare(strict_types=1);

namespace Nova\Publish;

use FilesystemIterator;
use Nova\Core\Files;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Strategy D (05 §8): two slots, `_site/slot-a/` and `_site/slot-b/`; the
 * `_site/ACTIVE_B` marker (present = slot B) chooses the live one per request
 * in .htaccess. Releases are built into the archive (`storage/releases/<id>/`,
 * also what rollback restores from), copied into the inactive slot, and made
 * live by creating or deleting the marker (both atomic). A slot is reused only
 * after a grace period, so requests still reading it are never cut off.
 */
final readonly class SlotSwitcher implements ReleaseSwitcher
{
    public const int GRACE_SECONDS = 120;

    public function __construct(
        private string $siteDir,
        private string $archiveDir,
        private Clock $clock = new SystemClock(),
    ) {}

    public function name(): string
    {
        return 'slots';
    }

    public function prepare(string $id): string
    {
        $dir = $this->archive($id);
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
        if (!is_dir($this->archive($id))) {
            throw new RuntimeException("Release {$id} is not in the archive.");
        }
        $wait = $this->waitBefore($id);
        if ($wait > 0) {
            throw new RuntimeException("The inactive slot was live until moments ago; it can be reused in {$wait} s.");
        }
        $target = $this->activeSlot() === 'a' ? 'b' : 'a';
        $slot = $this->slot($target);
        Files::removeTree($slot);
        self::copyTree($this->archive($id), $slot);
        file_put_contents($slot . '/.release', $id);

        $marker = $this->siteDir . '/ACTIVE_B';
        if ($target === 'b') {
            Files::writeAtomic($marker, $id . "\n");
        } elseif (is_file($marker) && !unlink($marker)) {
            throw new RuntimeException('Cannot remove the slot marker.');
        }
        // The slot that just went inactive starts its grace period now.
        Files::writeAtomic($this->siteDir . '/.slot-' . ($target === 'a' ? 'b' : 'a') . '-inactive', (string) $this->clock->now());
    }

    public function current(): ?string
    {
        $file = $this->slot($this->activeSlot()) . '/.release';
        $id = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $id === '' ? null : $id;
    }

    public function has(string $id): bool
    {
        return is_dir($this->archive($id));
    }

    public function discard(string $id): void
    {
        if ($this->current() === $id) {
            throw new RuntimeException("Release {$id} is live and cannot be removed.");
        }
        Files::removeTree($this->archive($id));
    }

    public function waitBefore(string $id): int
    {
        $inactive = $this->activeSlot() === 'a' ? 'b' : 'a';
        $file = $this->siteDir . '/.slot-' . $inactive . '-inactive';
        if (!is_file($file) || !is_dir($this->slot($inactive))) {
            return 0;
        }
        $since = (int) file_get_contents($file);

        return max(0, $since + self::GRACE_SECONDS - $this->clock->now());
    }

    public function releaseDir(string $id): string
    {
        return $this->archive($id);
    }

    /** 'a' or 'b': the slot the marker currently selects. */
    public function activeSlot(): string
    {
        return is_file($this->siteDir . '/ACTIVE_B') ? 'b' : 'a';
    }

    private function slot(string $name): string
    {
        return $this->siteDir . '/slot-' . $name;
    }

    private function archive(string $id): string
    {
        if (preg_match('/^R-[0-9]{8}-[0-9]{6}-[0-9a-f]{4}$/', $id) !== 1) {
            throw new RuntimeException("Invalid release id {$id}");
        }

        return $this->archiveDir . '/' . $id;
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0775, true)) {
            throw new RuntimeException("Cannot create {$to}");
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            \assert($item instanceof SplFileInfo);
            $dest = $to . substr($item->getPathname(), \strlen($from));
            $ok = $item->isDir() ? (is_dir($dest) || mkdir($dest, 0775)) : copy($item->getPathname(), $dest);
            if (!$ok) {
                throw new RuntimeException("Cannot copy {$item->getPathname()}");
            }
        }
    }
}
