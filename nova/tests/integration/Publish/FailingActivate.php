<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Publish;

use Nova\Publish\ReleaseSwitcher;
use RuntimeException;

/** Test double: the switch itself fails (an ordinary error, not a killed process). */
final readonly class FailingActivate implements ReleaseSwitcher
{
    public function __construct(private ReleaseSwitcher $inner) {}

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
        throw new RuntimeException('Cannot switch the current release.');
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
