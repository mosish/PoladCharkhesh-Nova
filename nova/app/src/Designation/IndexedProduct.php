<?php

declare(strict_types=1);

namespace Nova\Designation;

final readonly class IndexedProduct
{
    /** @param list<IndexedVariant> $variants */
    public function __construct(
        public string $id,
        public string $designation,
        public string $key,
        public string $family,
        public bool $baseListed,
        public array $variants,
    ) {}

    /**
     * The listed items, as named to the visitor: the base designation first
     * when base_listed is on, then the listed variants in catalogue order.
     *
     * @return list<string>
     */
    public function listedDesignations(): array
    {
        $listed = $this->baseListed ? [$this->designation] : [];
        foreach ($this->variants as $variant) {
            if ($variant->listed) {
                $listed[] = $variant->designation;
            }
        }

        return $listed;
    }
}
