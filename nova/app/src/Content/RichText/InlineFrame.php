<?php

declare(strict_types=1);

namespace Nova\Content\RichText;

/** One open construct while parsing the inline syntax (root, strong, em or link label). */
final class InlineFrame
{
    /** @var list<array<string, mixed>> */
    public array $children = [];

    public function __construct(
        public readonly string $type,
        public readonly string $start,
    ) {}
}
