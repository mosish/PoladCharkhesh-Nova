<?php

declare(strict_types=1);

namespace Nova\Catalogue;

/** Fields an editor may change on a Product; null means "unchanged". */
final readonly class ProductChanges
{
    public function __construct(
        public ?string $designation = null,
        public ?int $familyId = null,
        public ?string $slug = null,
        public ?bool $baseListed = null,
        public ?bool $showInExplorer = null,
    ) {}
}
