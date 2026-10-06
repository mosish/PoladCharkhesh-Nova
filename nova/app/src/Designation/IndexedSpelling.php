<?php

declare(strict_types=1);

namespace Nova\Designation;

/** An Owner-recorded alternative writing of exactly one Product or variant. */
final readonly class IndexedSpelling
{
    public function __construct(
        public string $spelling,
        public string $key,
        public IndexedProduct|IndexedVariant $target,
    ) {}
}
