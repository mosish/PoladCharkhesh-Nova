<?php

declare(strict_types=1);

namespace Nova\Content\RichText;

/**
 * Validates a rich-content document against its field's profile on every save
 * and again at publish (05 §27). Strict: unknown keys, types, marks or targets
 * are errors, never silently dropped.
 */
final class Schema
{
    /** @return list<string> errors; empty when valid */
    public static function errors(mixed $document, Profile $profile): array
    {
        $errors = [];
        if (!\is_array($document) || ($document['format'] ?? null) !== RichText::FORMAT || !\is_array($document['blocks'] ?? null) || !array_is_list($document['blocks'])) {
            return ['document: expected {"format": "' . RichText::FORMAT . '", "blocks": [...]}'];
        }
        self::keys($document, ['format', 'blocks'], 'document', $errors);
        $blocks = $document['blocks'];
        if (\count($blocks) > $profile->maxBlocks()) {
            $errors[] = 'document: at most ' . $profile->maxBlocks() . ' block(s) in the ' . $profile->value . ' profile';
        }
        foreach ($blocks as $i => $block) {
            self::block($block, $profile, "blocks[{$i}]", $errors);
        }

        return $errors;
    }

    /** @param list<string> $errors */
    private static function block(mixed $block, Profile $profile, string $at, array &$errors): void
    {
        $type = \is_array($block) ? ($block['type'] ?? null) : null;
        if (!\is_array($block) || !\is_string($type)) {
            $errors[] = "{$at}: a block must be an object with a type";

            return;
        }
        if (!\in_array($type, $profile->blocks(), true)) {
            $errors[] = "{$at}: \"{$type}\" is not allowed in the {$profile->value} profile";

            return;
        }
        switch ($type) {
            case 'paragraph':
                self::keys($block, ['type', 'content'], $at, $errors);
                self::inline($block['content'] ?? null, "{$at}.content", $errors, true);
                break;
            case 'heading':
                self::keys($block, ['type', 'level', 'content'], $at, $errors);
                if (!\in_array($block['level'] ?? null, [1, 2], true)) {
                    $errors[] = "{$at}.level: 1 or 2";
                }
                self::inline($block['content'] ?? null, "{$at}.content", $errors, true);
                break;
            case 'list':
                self::keys($block, ['type', 'ordered', 'items'], $at, $errors);
                if (!\is_bool($block['ordered'] ?? null)) {
                    $errors[] = "{$at}.ordered: true or false";
                }
                self::items($block['items'] ?? null, "{$at}.items", 1, $errors);
                break;
            case 'table':
                self::keys($block, ['type', 'caption', 'header', 'rows'], $at, $errors);
                self::inline($block['caption'] ?? null, "{$at}.caption", $errors, true);
                $header = $block['header'] ?? null;
                if (!\is_array($header) || !array_is_list($header) || $header === [] || \count($header) > RichText::MAX_COLUMNS) {
                    $errors[] = "{$at}.header: 1 to " . RichText::MAX_COLUMNS . ' header cells';
                    break;
                }
                foreach ($header as $c => $cell) {
                    self::inline($cell, "{$at}.header[{$c}]", $errors, true);
                }
                $rows = $block['rows'] ?? null;
                if (!\is_array($rows) || !array_is_list($rows) || \count($rows) > RichText::MAX_ROWS) {
                    $errors[] = "{$at}.rows: a list of at most " . RichText::MAX_ROWS . ' rows';
                    break;
                }
                foreach ($rows as $r => $row) {
                    if (!\is_array($row) || !array_is_list($row) || \count($row) !== \count($header)) {
                        $errors[] = "{$at}.rows[{$r}]: exactly " . \count($header) . ' cells';
                        continue;
                    }
                    foreach ($row as $c => $cell) {
                        self::inline($cell, "{$at}.rows[{$r}][{$c}]", $errors, false);
                    }
                }
                break;
            case 'figure':
                self::keys($block, ['type', 'media', 'alt', 'decorative', 'caption'], $at, $errors, ['caption']);
                if (!\is_int($block['media'] ?? null) || $block['media'] < 1) {
                    $errors[] = "{$at}.media: a media reference";
                }
                $decorative = $block['decorative'] ?? null;
                if (!\is_bool($decorative)) {
                    $errors[] = "{$at}.decorative: true or false";
                }
                $alt = $block['alt'] ?? null;
                if (!\is_string($alt) || mb_strlen($alt, 'UTF-8') > 500) {
                    $errors[] = "{$at}.alt: text of at most 500 characters";
                } elseif ($decorative === false && trim($alt) === '') {
                    $errors[] = "{$at}.alt: an informative figure needs alt text (ALT-INF)";
                }
                if (\array_key_exists('caption', $block)) {
                    self::inline($block['caption'], "{$at}.caption", $errors, true);
                }
                break;
            case 'document':
                self::keys($block, ['type', 'document', 'label'], $at, $errors);
                if (!\is_int($block['document'] ?? null) || $block['document'] < 1) {
                    $errors[] = "{$at}.document: a document reference";
                }
                self::inline($block['label'] ?? null, "{$at}.label", $errors, true);
                break;
        }
    }

    /** @param list<string> $errors */
    private static function items(mixed $items, string $at, int $depth, array &$errors): void
    {
        if (!\is_array($items) || !array_is_list($items) || $items === [] || \count($items) > RichText::MAX_ITEMS) {
            $errors[] = "{$at}: 1 to " . RichText::MAX_ITEMS . ' items';

            return;
        }
        foreach ($items as $i => $item) {
            if (!\is_array($item)) {
                $errors[] = "{$at}[{$i}]: an item must be an object";
                continue;
            }
            self::keys($item, ['content', 'items'], "{$at}[{$i}]", $errors, ['items']);
            self::inline($item['content'] ?? null, "{$at}[{$i}].content", $errors, true);
            if (\array_key_exists('items', $item)) {
                if ($depth >= 2) {
                    $errors[] = "{$at}[{$i}].items: lists nest one level only";
                } else {
                    self::items($item['items'], "{$at}[{$i}].items", $depth + 1, $errors);
                }
            }
        }
    }

    /** @param list<string> $errors */
    private static function inline(mixed $nodes, string $at, array &$errors, bool $required, bool $inLink = false): void
    {
        if (!\is_array($nodes) || !array_is_list($nodes)) {
            $errors[] = "{$at}: a list of inline nodes";

            return;
        }
        if ($required && $nodes === []) {
            $errors[] = "{$at}: text is required";
        }
        foreach ($nodes as $i => $node) {
            $here = "{$at}[{$i}]";
            $type = \is_array($node) ? ($node['type'] ?? null) : null;
            if (!\is_array($node)) {
                $errors[] = "{$here}: an inline node must be an object";
                continue;
            }
            match ($type) {
                'text' => self::text($node, $here, $errors),
                'code' => self::code($node, $here, $errors),
                'link' => $inLink
                    ? $errors[] = "{$here}: links cannot contain links"
                    : self::link($node, $here, $errors),
                default => $errors[] = "{$here}: unknown inline type",
            };
        }
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string> $errors
     */
    private static function text(array $node, string $at, array &$errors): void
    {
        self::keys($node, ['type', 'text', 'marks'], $at, $errors);
        self::string($node['text'] ?? null, "{$at}.text", $errors);
        $marks = $node['marks'] ?? null;
        $valid = \is_array($marks) && array_is_list($marks)
            && \in_array($marks, [[], ['strong'], ['em'], ['strong', 'em']], true);
        if (!$valid) {
            $errors[] = "{$at}.marks: a subset of [\"strong\", \"em\"] in that order";
        }
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string> $errors
     */
    private static function code(array $node, string $at, array &$errors): void
    {
        self::keys($node, ['type', 'text'], $at, $errors);
        self::string($node['text'] ?? null, "{$at}.text", $errors);
        if (\is_string($node['text'] ?? null) && (str_contains($node['text'], '`') || str_contains($node['text'], "\n"))) {
            $errors[] = "{$at}.text: a code cannot contain a backtick or a line break";
        }
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string> $errors
     */
    private static function link(array $node, string $at, array &$errors): void
    {
        self::keys($node, ['type', 'target', 'children'], $at, $errors);
        $target = $node['target'] ?? null;
        if (!\is_string($target) || LinkTarget::parse($target) === null) {
            $errors[] = "{$at}.target: an https URL or product:/section:/document: reference";
        }
        self::inline($node['children'] ?? null, "{$at}.children", $errors, true, true);
    }

    /** @param list<string> $errors */
    private static function string(mixed $value, string $at, array &$errors): void
    {
        if (!\is_string($value) || $value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > RichText::MAX_TEXT) {
            $errors[] = "{$at}: non-empty UTF-8 text of at most " . RichText::MAX_TEXT . ' characters';
        } elseif (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $value) === 1) {
            $errors[] = "{$at}: control characters are not allowed";
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $allowed
     * @param list<string> $errors
     * @param list<string> $optional
     */
    private static function keys(array $value, array $allowed, string $at, array &$errors, array $optional = []): void
    {
        foreach (array_keys($value) as $key) {
            if (!\in_array($key, $allowed, true)) {
                $errors[] = "{$at}: unknown field \"{$key}\"";
            }
        }
        foreach (array_diff($allowed, $optional) as $key) {
            if (!\array_key_exists($key, $value)) {
                $errors[] = "{$at}: missing field \"{$key}\"";
            }
        }
    }
}
