<?php

declare(strict_types=1);

namespace Nova\Media;

/** A sanitised SVG: the rebuilt file, its recorded size, and what was removed from the upload. */
final readonly class SvgResult
{
    /** @param list<string> $removed */
    public function __construct(
        public string $svg,
        public int $width,
        public int $height,
        public array $removed,
    ) {}
}
