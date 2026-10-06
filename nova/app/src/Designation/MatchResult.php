<?php

declare(strict_types=1);

namespace Nova\Designation;

/** The outcome of matching one requested designation (docs/designation-matching.md §5). */
final readonly class MatchResult
{
    /**
     * @param list<string> $listed
     * @param list<string> $suffix
     */
    public function __construct(
        public string $requested,
        public string $key,
        public MatchType $type,
        public ?string $via = null,
        public ?string $spelling = null,
        public ?string $product = null,
        public ?string $variant = null,
        public ?string $designation = null,
        public array $listed = [],
        public array $suffix = [],
        public ?NoMatchReason $reason = null,
    ) {}

    /** @return array{requested: string, key: string, type: string, via: ?string, spelling: ?string, product: ?string, variant: ?string, designation: ?string, listed: list<string>, suffix: list<string>, reason: ?string} */
    public function toArray(): array
    {
        return [
            'requested' => $this->requested,
            'key' => $this->key,
            'type' => $this->type->value,
            'via' => $this->via,
            'spelling' => $this->spelling,
            'product' => $this->product,
            'variant' => $this->variant,
            'designation' => $this->designation,
            'listed' => $this->listed,
            'suffix' => $this->suffix,
            'reason' => $this->reason?->value,
        ];
    }
}
