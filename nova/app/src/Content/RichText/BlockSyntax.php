<?php

declare(strict_types=1);

namespace Nova\Content\RichText;

/**
 * The Admin's plain-text form of a Basic-profile document (paragraphs and
 * lists, 05 §27): never HTML, so nothing typed becomes markup.
 *
 *   A paragraph is one or more lines; a blank line ends it.
 *   - an unordered list item         1. an ordered list item
 *     - a nested item (two spaces; one level, as the schema allows)
 *
 * Inline text uses InlineSyntax. A paragraph line that would otherwise read
 * as a list item starts with a backslash. parse(format(doc)) === doc for
 * every valid Basic document; parse() output still goes through Schema.
 */
final class BlockSyntax
{
    private const string ITEM = '/^(  )?(-|\d+\.) (.*)$/';

    /** @return array{format: string, blocks: list<array<string, mixed>>}|null null when the text is empty */
    public static function parse(string $text): ?array
    {
        $lines = preg_split('/\R/u', str_replace("\t", '  ', $text)) ?: [];
        /** @var list<array<string, mixed>> $blocks */
        $blocks = [];
        /** @var list<string> $paragraph */
        $paragraph = [];
        /** @var array{type: string, ordered: bool, items: list<array<string, mixed>>}|null $list */
        $list = null;

        foreach ($lines as $raw) {
            $line = rtrim($raw);
            if (trim($line) === '') {
                self::endParagraph($blocks, $paragraph);
                self::endList($blocks, $list);
                continue;
            }
            if ($paragraph === [] && preg_match(self::ITEM, $line, $m) === 1) {
                $nested = $m[1] === '  ';
                $ordered = $m[2] !== '-';
                $item = ['content' => InlineSyntax::parse($m[3])];
                if ($nested && $list !== null && $list['items'] !== []) {
                    $last = \count($list['items']) - 1;
                    $children = \is_array($list['items'][$last]['items'] ?? null) ? array_values($list['items'][$last]['items']) : [];
                    $children[] = $item;
                    $list['items'][$last]['items'] = $children;
                    continue;
                }
                if ($list !== null && $list['ordered'] !== $ordered) {
                    self::endList($blocks, $list);
                }
                $list ??= ['type' => 'list', 'ordered' => $ordered, 'items' => []];
                $list['items'][] = $item;
                continue;
            }
            self::endList($blocks, $list);
            $trimmed = ltrim($line);
            $paragraph[] = str_starts_with($trimmed, '\\') && preg_match(self::ITEM, substr($trimmed, 1)) === 1 ? substr($trimmed, 1) : trim($line);
        }
        self::endParagraph($blocks, $paragraph);
        self::endList($blocks, $list);

        return \count($blocks) === 0 ? null : RichText::document($blocks);
    }

    /**
     * @param array<array-key, mixed> $document a Schema-valid document
     *
     * @return string|null null when the document has blocks this form cannot show (edit it elsewhere)
     */
    public static function format(array $document): ?string
    {
        $out = [];
        foreach (\is_array($document['blocks'] ?? null) ? $document['blocks'] : [] as $block) {
            if (!\is_array($block)) {
                return null;
            }
            if (($block['type'] ?? null) === 'paragraph') {
                $text = self::inline($block['content'] ?? null);
                if ($text === null) {
                    return null;
                }
                $out[] = preg_match(self::ITEM, $text) === 1 ? '\\' . $text : $text;
            } elseif (($block['type'] ?? null) === 'list' && \is_array($block['items'] ?? null)) {
                $marker = static fn(int $n): string => ($block['ordered'] ?? false) === true ? "{$n}." : '-';
                $lines = [];
                $n = 0;
                foreach ($block['items'] as $item) {
                    if (!\is_array($item)) {
                        return null;
                    }
                    $text = self::inline($item['content'] ?? null);
                    if ($text === null) {
                        return null;
                    }
                    $lines[] = $marker(++$n) . ' ' . $text;
                    $m = 0;
                    foreach (\is_array($item['items'] ?? null) ? $item['items'] : [] as $sub) {
                        $subText = \is_array($sub) ? self::inline($sub['content'] ?? null) : null;
                        if ($subText === null) {
                            return null;
                        }
                        $lines[] = '  ' . $marker(++$m) . ' ' . $subText;
                    }
                }
                $out[] = implode("\n", $lines);
            } else {
                return null;
            }
        }

        return implode("\n\n", $out);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $paragraph
     */
    private static function endParagraph(array &$blocks, array &$paragraph): void
    {
        if ($paragraph !== []) {
            $blocks[] = ['type' => 'paragraph', 'content' => InlineSyntax::parse(implode(' ', $paragraph))];
            $paragraph = [];
        }
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array{type: string, ordered: bool, items: list<array<string, mixed>>}|null $list
     *
     * @param-out null $list
     */
    private static function endList(array &$blocks, ?array &$list): void
    {
        if ($list !== null) {
            $blocks[] = $list;
            $list = null;
        }
    }

    private static function inline(mixed $nodes): ?string
    {
        if (!\is_array($nodes) || !array_is_list($nodes)) {
            return null;
        }
        foreach ($nodes as $node) {
            if (!\is_array($node)) {
                return null;
            }
        }

        /** @var list<array<string, mixed>> $nodes */
        return InlineSyntax::format($nodes);
    }
}
