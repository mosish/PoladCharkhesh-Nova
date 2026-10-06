<?php

declare(strict_types=1);

namespace Nova\Core;

/** Locations inside one Nova code tree (the repository's nova/ or a deployed ~/app-releases/<version>/). */
final readonly class Paths
{
    public function __construct(public string $root) {}

    public static function fromHere(): self
    {
        return new self(\dirname(__DIR__, 3));
    }

    public function migrations(): string
    {
        return $this->root . '/app/migrations';
    }

    public function devCode(): string
    {
        return $this->root . '/app/dev';
    }
}
