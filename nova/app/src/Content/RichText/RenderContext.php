<?php

declare(strict_types=1);

namespace Nova\Content\RichText;

/**
 * What the renderer needs from the release being built: the language, the
 * heading level of the surrounding section, and resolution of internal
 * references (null = broken, which blocks the publish).
 */
interface RenderContext
{
    public function language(): string;

    /** The HTML level of a level-1 rich-content heading (e.g. 3 inside an h2 section). */
    public function headingBase(): int;

    public function productUrl(string $slug): ?string;

    public function sectionUrl(string $id): ?string;

    /** @return array{url: string, label: string}|null */
    public function document(int $id): ?array;

    /** @return array{src: string, width: int, height: int, srcset?: ?string}|null */
    public function media(int $id): ?array;
}
