<?php

declare(strict_types=1);

namespace Nova\Media;

use RuntimeException;

/**
 * A refused media operation. `$reason` is stable and machine-readable:
 * too_large, unsupported, invalid, forbidden, not_found, duplicate,
 * referenced, stale.
 */
final class MediaError extends RuntimeException
{
    /** @param list<string> $detail what was wrong, for the Owner (e.g. what an SVG contained) */
    public function __construct(public readonly string $reason, string $message, public readonly array $detail = [])
    {
        parent::__construct($message);
    }
}
