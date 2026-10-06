<?php

declare(strict_types=1);

namespace Nova\Content\RichText;

use RuntimeException;

/** Internal references that do not resolve in the release; a publish blocker (05 §7). */
final class BrokenReferences extends RuntimeException
{
    /** @param list<string> $references */
    public function __construct(public readonly array $references)
    {
        parent::__construct('Broken references: ' . implode(', ', $references));
    }
}
