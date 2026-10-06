<?php

declare(strict_types=1);

namespace Nova\Designation;

final readonly class IndexedVariant
{
    public function __construct(
        public string $id,
        public string $productId,
        public string $designation,
        public string $key,
        public bool $listed,
    ) {}
}
