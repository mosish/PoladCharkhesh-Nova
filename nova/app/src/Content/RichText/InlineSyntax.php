<?php

declare(strict_types=1);

namespace Nova\Content\RichText;

/**
 * The tiny editor syntax of text blocks (05 §27): **strong**, *emphasis*,
 * [text](target), `code`, and a backslash to escape any of \ * ` [ ].
 * It is never HTML: anything typed is text. parse() and format() round-trip:
 * parse(format(nodes)) === nodes for every valid node list.
 */
final class InlineSyntax
{
    private const string SPECIAL = '\\*`[]';

    /** @var list<InlineFrame> */
    private array $stack;

    private string $text = '';

    private function __construct()
    {
        $this->stack = [new InlineFrame('root', '')];
    }

    /** @return list<array<string, mixed>> inline nodes */
    public static function parse(string $source): array
    {
        return (new self())->run(str_replace(["\r\n", "\r"], "\n", $source));
    }

    /** @param list<array<string, mixed>> $nodes */
    public static function format(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['type'] ?? null) {
                'text' => self::wrap(self::escape(self::str($node['text'] ?? null)), self::marks($node)),
                'code' => '`' . self::str($node['text'] ?? null) . '`',
                'link' => '[' . self::format(self::nodes($node['children'] ?? null)) . '](' . self::str($node['target'] ?? null) . ')',
                default => '',
            };
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function run(string $source): array
    {
        $length = \strlen($source);
        for ($i = 0; $i < $length;) {
            $c = $source[$i];
            $next = $source[$i + 1] ?? '';
            if ($c === '\\' && $next !== '' && str_contains(self::SPECIAL, $next)) {
                $this->text .= $next;
                $i += 2;
            } elseif ($c === '`' && ($close = strpos($source, '`', $i + 1)) !== false && $close > $i + 1) {
                $this->add(['type' => 'code', 'text' => substr($source, $i + 1, $close - $i - 1)]);
                $i = $close + 1;
            } elseif ($c === '*') {
                $run = strspn($source, '*', $i);
                $i += $run;
                $this->stars($run);
            } elseif ($c === '[' && !$this->inside('link')) {
                $this->flush();
                $this->stack[] = new InlineFrame('link', '[');
                ++$i;
            } elseif ($c === ']' && $this->top()->type === 'link' && $next === '(' && ($end = strpos($source, ')', $i + 2)) !== false) {
                $this->flush();
                $frame = $this->pop();
                $this->add(['type' => 'link', 'target' => substr($source, $i + 2, $end - $i - 2), 'children' => self::merge($frame->children)]);
                $i = $end + 1;
            } else {
                $this->text .= $c;
                ++$i;
            }
        }
        $this->flush();

        // Unclosed constructs are literal text.
        while (\count($this->stack) > 1) {
            $frame = $this->pop();
            $this->top()->children[] = ['type' => 'text', 'text' => $frame->start, 'marks' => []];
            foreach ($frame->children as $child) {
                $this->top()->children[] = $child;
            }
        }

        return self::merge($this->stack[0]->children);
    }

    /** A run of stars closes from the innermost construct outwards, then opens with what is left. */
    private function stars(int $run): void
    {
        $this->flush();
        while ($run > 0) {
            $type = $this->top()->type;
            if ($type === 'em') {
                $this->close('em');
                --$run;
            } elseif ($type === 'strong' && $run >= 2) {
                $this->close('strong');
                $run -= 2;
            } else {
                break;
            }
        }
        // Opening uses at most three stars (strong, then emphasis); any extra are text.
        if ($run > 3) {
            $this->text = str_repeat('*', $run - 3);
            $this->flush();
            $run = 3;
        }
        if ($run >= 2) {
            $this->stack[] = new InlineFrame('strong', '**');
            $run -= 2;
        }
        if ($run === 1) {
            $this->stack[] = new InlineFrame('em', '*');
        }
    }

    private function close(string $mark): void
    {
        $frame = $this->pop();
        if ($frame->children === []) {
            // An empty pair stays literal.
            $this->add(['type' => 'text', 'text' => $frame->start . $frame->start, 'marks' => []]);

            return;
        }
        foreach ($frame->children as $child) {
            $this->top()->children[] = self::mark($child, $mark);
        }
    }

    private function flush(): void
    {
        if ($this->text !== '') {
            $this->top()->children[] = ['type' => 'text', 'text' => $this->text, 'marks' => []];
            $this->text = '';
        }
    }

    /** @param array<string, mixed> $node */
    private function add(array $node): void
    {
        $this->flush();
        $this->top()->children[] = $node;
    }

    private function top(): InlineFrame
    {
        return $this->stack[\count($this->stack) - 1];
    }

    private function pop(): InlineFrame
    {
        $frame = array_pop($this->stack);

        return $frame ?? new InlineFrame('root', '');
    }

    private function inside(string $type): bool
    {
        foreach ($this->stack as $frame) {
            if ($frame->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function mark(array $node, string $mark): array
    {
        if (($node['type'] ?? null) === 'text') {
            $node['marks'] = array_values(array_intersect(['strong', 'em'], [...self::marks($node), $mark]));
        } elseif (($node['type'] ?? null) === 'link') {
            $node['children'] = array_map(static fn(array $c): array => self::mark($c, $mark), self::nodes($node['children'] ?? null));
        }

        return $node;
    }

    /**
     * Joins adjacent text nodes with the same marks.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function merge(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $last = \count($out) - 1;
            if (($node['type'] ?? null) === 'text' && $last >= 0 && ($out[$last]['type'] ?? null) === 'text' && self::marks($out[$last]) === self::marks($node)) {
                $out[$last]['text'] = self::str($out[$last]['text'] ?? null) . self::str($node['text'] ?? null);
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }

    /** @param list<string> $marks */
    private static function wrap(string $text, array $marks): string
    {
        $open = (\in_array('strong', $marks, true) ? '**' : '') . (\in_array('em', $marks, true) ? '*' : '');

        return $open . $text . strrev($open);
    }

    private static function escape(string $text): string
    {
        return addcslashes($text, '\\*`[]');
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return list<string>
     */
    private static function marks(array $node): array
    {
        $marks = $node['marks'] ?? [];

        return \is_array($marks) ? array_values(array_filter($marks, \is_string(...))) : [];
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /** @return list<array<string, mixed>> */
    private static function nodes(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $nodes = [];
        foreach ($value as $node) {
            if (\is_array($node)) {
                /** @var array<string, mixed> $node */
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
