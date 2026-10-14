<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Content\RichText\RenderContext;

/** Resolves rich-content references against the release being built. */
final readonly class SiteRenderContext implements RenderContext
{
    public const array SECTIONS = ['find', 'families', 'explorer', 'anatomy', 'engineering', 'applications', 'documents', 'process', 'company', 'contact'];

    /**
     * @param array<string, true> $productSlugs
     * @param array<int, array{src: string, width: int, height: int, srcset: ?string}> $media
     * @param array<int, array{url: string, bytes: int, title_fa: string, title_en: string}> $documents
     */
    public function __construct(
        private string $lang,
        private array $productSlugs,
        private int $headingBase = 3,
        private array $media = [],
        private array $documents = [],
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
        $d = $this->documents[$id] ?? null;
        if ($d === null) {
            return null;
        }

        return ['url' => $d['url'], 'label' => 'PDF · ' . self::size($d['bytes'])];
    }

    public function media(int $id): ?array
    {
        return $this->media[$id] ?? null;
    }

    /** The size shown before download; units stay Latin (Q2). */
    public static function size(int $bytes): string
    {
        return $bytes < 1_048_576 ? max(1, (int) round($bytes / 1024)) . ' KB' : round($bytes / 1_048_576, 1) . ' MB';
    }
}
