<?php

declare(strict_types=1);

namespace Nova\Content\RichText;

/**
 * The Nova rich-content document (05 §27, D22/E-8): one JSON document per
 * field per language, `{"format": "nova-rich/1", "blocks": [...]}`.
 *
 * Blocks: paragraph {content} · heading {level 1|2, content} · list {ordered,
 * items: [{content, items?}]} (one nesting level) · table {caption, header,
 * rows} · figure {media, alt, decorative, caption?} · document {document, label}.
 * Inline nodes: text {text, marks ⊆ [strong, em]} · code {text} (a designation
 * or code, rendered bidi-isolated in the mono face) · link {target, children}.
 * Link targets: https URL, `product:<slug>`, `section:<id>`, `document:<id>`.
 *
 * Limits are abuse caps, not performance budgets (D14/D17).
 */
final class RichText
{
    public const string FORMAT = 'nova-rich/1';
    public const int MAX_BLOCKS = 300;
    public const int MAX_TEXT = 10000;
    public const int MAX_ITEMS = 200;
    public const int MAX_COLUMNS = 12;
    public const int MAX_ROWS = 200;
    public const int MAX_URL = 2048;

    /** @param list<array<string, mixed>> $blocks
     * @return array{format: string, blocks: list<array<string, mixed>>} */
    public static function document(array $blocks): array
    {
        return ['format' => self::FORMAT, 'blocks' => $blocks];
    }

    /** @return array{format: string, blocks: list<array<string, mixed>>} a document with one paragraph parsed from editor syntax */
    public static function paragraph(string $syntax): array
    {
        return self::document([['type' => 'paragraph', 'content' => InlineSyntax::parse($syntax)]]);
    }
}
