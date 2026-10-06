<?php

declare(strict_types=1);

namespace Nova\Publish;

/** Time source (seconds since the epoch); injectable so grace periods are testable. */
interface Clock
{
    public function now(): int;
}
