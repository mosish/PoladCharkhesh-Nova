<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Content\RichText\RenderContext;

/** Resolves rich-content references against the release being built. */
final readonly class SiteRenderContext implements RenderContext
{
    public const array SECTIONS = ['find', 'families', 'explorer', 'anatomy', 'engineering', 'applications', 'documents', 'process', 'company', 'contact'];

    /** @param array<string, true> $productSlugs */
    public function __construct(
        private string $lang,
        private array $productSlugs,
        private int $headingBase = 3,
    ) {}

    public function language(): string
    {
        return $this->lang;
    }

    public function headingBase(): int
    {
        return $this->headingBase;
    }

    public function productUrl(string $slug): ?string
    {
        return isset($this->productSlugs[$slug]) ? "/p/{$slug}/" : null;
    }

    public function sectionUrl(string $id): ?string
    {
        return \in_array($id, self::SECTIONS, true) ? "/#{$id}" : null;
    }

    public function document(int $id): ?array
    {
        return null; // documents are published with the media core (step 11)
    }

    public function media(int $id): ?array
    {
        return null; // media references are resolved with the media core (step 11)
    }
}
