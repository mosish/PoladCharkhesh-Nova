<?php

declare(strict_types=1);

namespace Nova\Content\RichText;

use InvalidArgumentException;

/**
 * Nova's own rich-content renderer (05 §27). It emits only allow-listed
 * elements; every text is escaped; codes are bidi-isolated in the mono face;
 * external links get rel="noopener noreferrer"; headings follow the context;
 * tables get a caption and header scope. A document must pass Schema first.
 */
final class Renderer
{
    /** @var list<string> */
    private array $broken = [];

    private function __construct(private readonly RenderContext $context) {}

    /**
     * @param array<array-key, mixed> $document
     *
     * @throws BrokenReferences when an internal reference does not resolve
     */
    public static function render(array $document, Profile $profile, RenderContext $context): string
    {
        $errors = Schema::errors($document, $profile);
        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid rich content: ' . implode('; ', $errors));
        }
        $renderer = new self($context);
        $html = '';
        /** @var list<array<string, mixed>> $blocks */
        $blocks = $document['blocks'];
        foreach ($blocks as $block) {
            $html .= $renderer->block($block);
        }
        if ($renderer->broken !== []) {
            throw new BrokenReferences(array_values(array_unique($renderer->broken)));
        }

        return $html;
    }

    /**
     * Plain text (for meta descriptions and search), with no markup at all.
     *
     * @param array<array-key, mixed> $document
     */
    public static function plainText(array $document): string
    {
        $parts = [];
        array_walk_recursive($document, static function (mixed $value, int|string $key) use (&$parts): void {
            if ($key === 'text' && \is_string($value)) {
                $parts[] = $value;
            }
        });

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * Every internal reference in a document, for reference tracking and checks.
     *
     * @param array<array-key, mixed> $document
     *
     * @return list<string> e.g. product:6205, document:3, media:7
     */
    public static function references(array $document): array
    {
        $refs = [];
        array_walk_recursive($document, static function (mixed $value, int|string $key) use (&$refs): void {
            if ($key === 'target' && \is_string($value) && LinkTarget::parse($value)?->kind !== 'external') {
                $refs[] = $value;
            }
        });
        $walk = static function (mixed $node) use (&$walk, &$refs): void {
            if (!\is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'figure' && \is_int($node['media'] ?? null)) {
                $refs[] = 'media:' . $node['media'];
            }
            if (($node['type'] ?? null) === 'document' && \is_int($node['document'] ?? null)) {
                $refs[] = 'document:' . $node['document'];
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($document);

        return array_values(array_unique($refs));
    }

    /** @param array<string, mixed> $block */
    private function block(array $block): string
    {
        return match ($block['type']) {
            'paragraph' => '<p>' . $this->inline($block['content']) . "</p>\n",
            'heading' => $this->heading($block),
            'list' => $this->list($block),
            'table' => $this->table($block),
            'figure' => $this->figure($block),
            'document' => $this->document($block),
            default => '',
        };
    }

    /** @param array<string, mixed> $block */
    private function heading(array $block): string
    {
        $level = min(6, $this->context->headingBase() + (\is_int($block['level']) ? $block['level'] : 1) - 1);

        return "<h{$level}>" . $this->inline($block['content']) . "</h{$level}>\n";
    }

    /** @param array<string, mixed> $block */
    private function list(array $block): string
    {
        $tag = $block['ordered'] === true ? 'ol' : 'ul';

        return $this->items($tag, $block['items']) . "\n";
    }

    private function items(string $tag, mixed $items): string
    {
        $html = "<{$tag}>";
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $html .= '<li>' . $this->inline($item['content'] ?? []);
            if (isset($item['items'])) {
                $html .= $this->items($tag, $item['items']);
            }
            $html .= '</li>';
        }

        return $html . "</{$tag}>";
    }

    /** @param array<string, mixed> $block */
    private function table(array $block): string
    {
        $html = '<div class="rc-table"><table><caption>' . $this->inline($block['caption']) . '</caption><thead><tr>';
        foreach (\is_array($block['header']) ? $block['header'] : [] as $cell) {
            $html .= '<th scope="col">' . $this->inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (\is_array($block['rows']) ? $block['rows'] : [] as $row) {
            $html .= '<tr>';
            foreach (\is_array($row) ? $row : [] as $cell) {
                $html .= '<td>' . $this->inline($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . "</tbody></table></div>\n";
    }

    /** @param array<string, mixed> $block */
    private function figure(array $block): string
    {
        $media = \is_int($block['media']) ? $this->context->media($block['media']) : null;
        if ($media === null) {
            $this->broken[] = 'media:' . self::scalar($block['media']);

            return '';
        }
        $alt = $block['decorative'] === true ? '' : self::scalar($block['alt']);
        $html = '<figure class="rc-figure"><img src="' . self::e($media['src']) . '" alt="' . self::e($alt) . '" width="' . $media['width'] . '" height="' . $media['height'] . '"' . (isset($media['srcset']) ? ' srcset="' . self::e($media['srcset']) . '" sizes="(min-width: 900px) 72ch, 100vw"' : '') . ' loading="lazy" decoding="async">';
        if (isset($block['caption'])) {
            $html .= '<figcaption>' . $this->inline($block['caption']) . '</figcaption>';
        }

        return $html . "</figure>\n";
    }

    /** @param array<string, mixed> $block */
    private function document(array $block): string
    {
        $doc = \is_int($block['document']) ? $this->context->document($block['document']) : null;
        if ($doc === null) {
            $this->broken[] = 'document:' . self::scalar($block['document']);

            return '';
        }

        return '<p class="rc-doc"><a href="' . self::e($doc['url']) . '">' . $this->inline($block['label']) . '</a> <span class="rc-doc__meta">' . self::e($doc['label']) . "</span></p>\n";
    }

    private function inline(mixed $nodes): string
    {
        $html = '';
        foreach (\is_array($nodes) ? $nodes : [] as $node) {
            if (!\is_array($node)) {
                continue;
            }
            $html .= match ($node['type'] ?? null) {
                'text' => $this->text($node),
                'code' => '<bdi class="mono" dir="ltr">' . self::e(self::scalar($node['text'] ?? '')) . '</bdi>',
                'link' => $this->link($node),
                default => '',
            };
        }

        return $html;
    }

    /** @param array<array-key, mixed> $node */
    private function text(array $node): string
    {
        $html = self::e(self::scalar($node['text'] ?? ''));
        $marks = \is_array($node['marks'] ?? null) ? $node['marks'] : [];
        if (\in_array('em', $marks, true)) {
            $html = "<em>{$html}</em>";
        }
        if (\in_array('strong', $marks, true)) {
            $html = "<strong>{$html}</strong>";
        }

        return $html;
    }

    /** @param array<array-key, mixed> $node */
    private function link(array $node): string
    {
        $target = LinkTarget::parse(self::scalar($node['target'] ?? ''));
        $label = $this->inline($node['children'] ?? []);
        if ($target === null) {
            return $label;
        }
        if ($target->kind === 'external') {
            return '<a href="' . self::e($target->value) . '" rel="noopener noreferrer">' . $label . '</a>';
        }
        $url = match ($target->kind) {
            'product' => $this->context->productUrl($target->value),
            'section' => $this->context->sectionUrl($target->value),
            default => $this->context->document((int) $target->value)['url'] ?? null,
        };
        if ($url === null) {
            $this->broken[] = "{$target->kind}:{$target->value}";

            return $label;
        }

        return '<a href="' . self::e($url) . '">' . $label . '</a>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
