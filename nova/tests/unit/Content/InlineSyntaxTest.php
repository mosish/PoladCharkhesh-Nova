<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Content;

use Nova\Content\RichText\InlineSyntax;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InlineSyntaxTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function t(string $text, string ...$marks): array
    {
        return ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    /** @return iterable<string, array{string, list<array<string, mixed>>}> */
    public static function cases(): iterable
    {
        yield 'plain' => ['plain text', [self::t('plain text')]];
        yield 'strong' => ['a **b** c', [self::t('a '), self::t('b', 'strong'), self::t(' c')]];
        yield 'emphasis' => ['*b*', [self::t('b', 'em')]];
        yield 'strong + emphasis' => ['***b***', [self::t('b', 'strong', 'em')]];
        yield 'nested' => ['**a *b* c**', [self::t('a ', 'strong'), self::t('b', 'strong', 'em'), self::t(' c', 'strong')]];
        yield 'code keeps specials' => ['see `6205-2RS*` now', [self::t('see '), ['type' => 'code', 'text' => '6205-2RS*'], self::t(' now')]];
        yield 'external link' => ['[site](https://example.test/a)', [['type' => 'link', 'target' => 'https://example.test/a', 'children' => [self::t('site')]]]];
        yield 'internal link with marks' => ['[**6205**](product:6205)', [['type' => 'link', 'target' => 'product:6205', 'children' => [self::t('6205', 'strong')]]]];
        yield 'escapes' => ['\\*not em\\* \\[x\\] \\` \\\\', [self::t('*not em* [x] ` \\')]];
        yield 'unclosed markers stay literal' => ['a **b', [self::t('a **b')]];
        yield 'empty pair stays literal' => ['****', [self::t('****')]];
        yield 'bracket without target' => ['[x] y', [self::t('[x] y')]];
        yield 'html is text' => ['<script>alert(1)</script>', [self::t('<script>alert(1)</script>')]];
        yield 'persian' => ['بلبرینگ **۶۲۰۵**', [self::t('بلبرینگ '), self::t('۶۲۰۵', 'strong')]];
        yield 'lone backtick' => ['a ` b', [self::t('a ` b')]];
    }

    /** @param list<array<string, mixed>> $nodes */
    #[DataProvider('cases')]
    public function testParse(string $source, array $nodes): void
    {
        self::assertSame($nodes, InlineSyntax::parse($source));
    }

    /** @param list<array<string, mixed>> $nodes */
    #[DataProvider('cases')]
    public function testFormatRoundTrips(string $source, array $nodes): void
    {
        self::assertSame($nodes, InlineSyntax::parse(InlineSyntax::format($nodes)));
    }

    public function testRandomNodeListsRoundTrip(): void
    {
        mt_srand(20261006);
        $alphabet = ['a', 'b', ' ', '*', '`', '[', ']', '(', ')', '\\', 'ی', '۶', '_', '-'];
        $word = static function () use ($alphabet): string {
            $s = '';
            for ($i = 0, $n = mt_rand(1, 6); $i < $n; ++$i) {
                $s .= $alphabet[mt_rand(0, \count($alphabet) - 1)];
            }

            return $s;
        };
        $markSets = [[], ['strong'], ['em'], ['strong', 'em']];
        for ($round = 0; $round < 2000; ++$round) {
            $nodes = [];
            for ($i = 0, $n = mt_rand(1, 5); $i < $n; ++$i) {
                $kind = mt_rand(0, 9);
                if ($kind < 6) {
                    $nodes[] = ['type' => 'text', 'text' => $word(), 'marks' => $markSets[mt_rand(0, 3)]];
                } elseif ($kind < 8) {
                    $nodes[] = ['type' => 'code', 'text' => str_replace('`', 'x', $word())];
                } else {
                    $nodes[] = ['type' => 'link', 'target' => 'product:p' . mt_rand(1, 9), 'children' => [['type' => 'text', 'text' => $word(), 'marks' => $markSets[mt_rand(0, 3)]]]];
                }
            }
            $expected = $this->canonical($nodes);
            self::assertSame($expected, InlineSyntax::parse(InlineSyntax::format($nodes)), 'round ' . $round . ': ' . InlineSyntax::format($nodes));
        }
    }

    public function testParsingIsIdempotentOnArbitraryInput(): void
    {
        mt_srand(7);
        $alphabet = ['a', ' ', '*', '**', '`', '[', ']', '(', ')', '](', 'https://x.test', '\\', 'ی'];
        for ($round = 0; $round < 2000; ++$round) {
            $source = '';
            for ($i = 0, $n = mt_rand(1, 12); $i < $n; ++$i) {
                $source .= $alphabet[mt_rand(0, \count($alphabet) - 1)];
            }
            $once = InlineSyntax::parse($source);
            self::assertSame($once, InlineSyntax::parse(InlineSyntax::format($once)), json_encode($source, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Adjacent texts with equal marks merge, exactly as the parser does.
     *
     * @param list<array<string, mixed>> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function canonical(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $last = \count($out) - 1;
            if ($node['type'] === 'text' && $last >= 0 && $out[$last]['type'] === 'text' && $out[$last]['marks'] === $node['marks']) {
                $out[$last]['text'] = (\is_string($out[$last]['text']) ? $out[$last]['text'] : '') . (\is_string($node['text']) ? $node['text'] : '');
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }
}
