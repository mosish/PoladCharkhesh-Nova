<?php

declare(strict_types=1);

namespace Nova\Catalogue;

/** Fields that may change on a ProductVariant; null means "unchanged". */
final readonly class VariantChanges
{
    public function __construct(
        public ?bool $listed = null,
        public ?string $status = null,
        public ?string $notes = null,
        public ?int $sort = null,
    ) {}
}
