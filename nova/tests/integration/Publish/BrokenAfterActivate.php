<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Publish;

use Nova\Publish\ReleaseSwitcher;
use RuntimeException;

/**
 * Test double: the switch happens, an ordinary error follows, and from then
 * on the live state cannot be read, so the engine's recovery fails too.
 */
final class BrokenAfterActivate implements ReleaseSwitcher
{
    private bool $broken = false;

    public function __construct(private readonly ReleaseSwitcher $inner) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function prepare(string $id): string
    {
        return $this->inner->prepare($id);
    }

    public function activate(string $id): void
    {
        $this->inner->activate($id);
        $this->broken = true;

        throw new RuntimeException('Switched, then the storage became unreadable (test).');
    }

    public function current(): ?string
    {
        if ($this->broken) {
            throw new RuntimeException('Cannot read the live release (test).');
        }

        return $this->inner->current();
    }

    public function has(string $id): bool
    {
        return $this->inner->has($id);
    }

    public function discard(string $id): void
    {
        $this->inner->discard($id);
    }

    public function waitBefore(string $id): int
    {
        return $this->inner->waitBefore($id);
    }

    public function releaseDir(string $id): string
    {
        return $this->inner->releaseDir($id);
    }
}
