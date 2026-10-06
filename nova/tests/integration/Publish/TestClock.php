<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Publish;

use Nova\Publish\Clock;

final class TestClock implements Clock
{
    public function __construct(public int $time = 1_800_000_000) {}

    public function now(): int
    {
        return $this->time;
    }
}
