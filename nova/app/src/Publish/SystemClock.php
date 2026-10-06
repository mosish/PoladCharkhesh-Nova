<?php

declare(strict_types=1);

namespace Nova\Publish;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
