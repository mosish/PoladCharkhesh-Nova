<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Publish;

use Nova\Publish\ReleaseSwitcher;
use RuntimeException;

/**
 * Test double: the switch happens for real, then an ordinary error is thrown
 * (not a killed process), so the engine's own error path recovers it.
 */
final class ThrowAfterActivate implements ReleaseSwitcher
{
    public bool $armed = true;

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
        if ($this->armed) {
            $this->armed = false;

            throw new RuntimeException('Switched, then failed while finishing (test).');
        }
    }

    public function current(): ?string
    {
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
