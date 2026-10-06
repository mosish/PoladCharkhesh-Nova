<?php

declare(strict_types=1);

namespace Nova\Provenance;

/** Exactly one designation: a Product's base designation itself, or one variant. */
final readonly class DesignationRef
{
    private function __construct(
        public ?int $productId,
        public ?int $variantId,
    ) {}

    public static function base(int $productId): self
    {
        return new self($productId, null);
    }

    public static function variant(int $variantId): self
    {
        return new self(null, $variantId);
    }
}
