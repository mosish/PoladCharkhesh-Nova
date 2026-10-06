<?php

declare(strict_types=1);

namespace Nova\Provenance;

/** One technical value as an editor enters it, with its provenance. */
final readonly class ValueInput
{
    public function __construct(
        public string $valueText,
        public string $status = 'pending',
        public ?int $sourceId = null,
        public ?string $checkedOn = null,
        public ?string $sourceLocator = null,
        public ?string $unit = null,
    ) {}
}
