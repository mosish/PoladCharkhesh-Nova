<?php

declare(strict_types=1);

namespace Nova\Designation;

use InvalidArgumentException;

/**
 * The designation as the visitor typed it: trimmed and length-limited, never
 * rewritten (docs/designation-matching.md §1). Kept verbatim from the explorer
 * through to the inquiry record.
 */
final readonly class RequestedDesignation
{
    /** Code points. */
    public const int MAX_LENGTH = 64;

    private function __construct(
        public string $text,
        public bool $tooLong,
    ) {}

    public static function from(string $raw): self
    {
        Normaliser::assertUtf8($raw);

        $ws = Normaliser::WHITESPACE;
        $trimmed = preg_replace("/^[{$ws}]+|[{$ws}]+\$/u", '', $raw);
        if ($trimmed === null) {
            throw new InvalidArgumentException('Requested designation could not be trimmed.');
        }

        if (mb_strlen($trimmed, 'UTF-8') > self::MAX_LENGTH) {
            return new self(mb_substr($trimmed, 0, self::MAX_LENGTH, 'UTF-8'), true);
        }

        return new self($trimmed, false);
    }
}
