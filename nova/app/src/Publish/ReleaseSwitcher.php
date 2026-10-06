<?php

declare(strict_types=1);

namespace Nova\Publish;

/**
 * Makes a built release live atomically (05 §8, C2). Two strategies, both
 * implemented and tested; the hosting probe picks which one runs (E-20):
 * S = SymlinkSwitcher, D = SlotSwitcher. Neither ever renames a live folder.
 */
interface ReleaseSwitcher
{
    public function name(): string;

    /** A fresh, empty directory to build release `$id` into. */
    public function prepare(string $id): string;

    /** Makes release `$id` live (its build must be complete and verified). */
    public function activate(string $id): void;

    /** The live release id, or null before the first publish. */
    public function current(): ?string;

    /** Whether release `$id` is still on disk (so it can be rolled back to). */
    public function has(string $id): bool;

    /** Removes a release that is not live (a failed build, or one pruned). */
    public function discard(string $id): void;

    /** Seconds until release `$id` can be activated (strategy D's slot grace period); 0 when ready. */
    public function waitBefore(string $id): int;

    /** Where release `$id`'s files are kept (its build directory). */
    public function releaseDir(string $id): string;
}
