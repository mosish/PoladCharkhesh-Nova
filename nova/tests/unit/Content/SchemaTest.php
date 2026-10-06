<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Content;

use Nova\Content\RichText\Profile;
use Nova\Content\RichText\RichText;
use Nova\Content\RichText\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function text(string $t = 'x'): array
    {
        return [['type' => 'text', 'text' => $t, 'marks' => []]];
    }

    /** @param array<string, mixed> $block
     * @return array<string, mixed> */
    private static function doc(array $block): array
    {
        return RichText::document([$block]);
    }

    public function testEveryBlockTypeIsValidInTheArticleProfile(): void
    {
        $doc = RichText::document([
            ['type' => 'paragraph', 'content' => self::text()],
            ['type' => 'heading', 'level' => 2, 'content' => self::text()],
            ['type' => 'list', 'ordered' => false, 'items' => [['content' => self::text(), 'items' => [['content' => self::text()]]]]],
            ['type' => 'table', 'caption' => self::text(), 'header' => [self::text(), self::text()], 'rows' => [[self::text(), []]]],
            ['type' => 'figure', 'media' => 3, 'alt' => 'Section drawing', 'decorative' => false, 'caption' => self::text()],
            ['type' => 'figure', 'media' => 4, 'alt' => '', 'decorative' => true],
            ['type' => 'document', 'document' => 5, 'label' => self::text()],
            ['type' => 'paragraph', 'content' => [['type' => 'link', 'target' => 'section:about', 'children' => [['type' => 'code', 'text' => '6205']]]]],
        ]);
        self::assertSame([], Schema::errors($doc, Profile::Article));
    }

    /** @return iterable<string, array{mixed, Profile, string}> */
    public static function invalid(): iterable
    {
        $p = static fn(array $content): array => self::doc(['type' => 'paragraph', 'content' => $content]);
        $link = static fn(string $target): array => $p([['type' => 'link', 'target' => $target, 'children' => self::text()]]);

        yield 'not a document' => ['<p>hi</p>', Profile::Basic, 'document'];
        yield 'wrong format' => [['format' => 'html', 'blocks' => []], Profile::Basic, 'document'];
        yield 'unknown document field' => [['format' => RichText::FORMAT, 'blocks' => [], 'html' => '<b>'], Profile::Basic, 'unknown field "html"'];
        yield 'heading not in basic' => [self::doc(['type' => 'heading', 'level' => 1, 'content' => self::text()]), Profile::Basic, 'not allowed in the basic profile'];
        yield 'list not in inline' => [self::doc(['type' => 'list', 'ordered' => true, 'items' => [['content' => self::text()]]]), Profile::Inline, 'not allowed in the inline profile'];
        yield 'two paragraphs inline' => [RichText::document([['type' => 'paragraph', 'content' => self::text()], ['type' => 'paragraph', 'content' => self::text()]]), Profile::Inline, 'at most 1 block'];
        yield 'raw html block' => [self::doc(['type' => 'html', 'html' => '<script>']), Profile::Article, 'not allowed'];
        yield 'heading level 3' => [self::doc(['type' => 'heading', 'level' => 3, 'content' => self::text()]), Profile::Article, 'level: 1 or 2'];
        yield 'list nested twice' => [self::doc(['type' => 'list', 'ordered' => true, 'items' => [['content' => self::text(), 'items' => [['content' => self::text(), 'items' => [['content' => self::text()]]]]]]]), Profile::Basic, 'nest one level'];
        yield 'table row width' => [self::doc(['type' => 'table', 'caption' => self::text(), 'header' => [self::text(), self::text()], 'rows' => [[self::text()]]]), Profile::Article, 'exactly 2 cells'];
        yield 'table without caption' => [self::doc(['type' => 'table', 'caption' => [], 'header' => [self::text()], 'rows' => []]), Profile::Article, 'caption: text is required'];
        yield 'informative figure without alt' => [self::doc(['type' => 'figure', 'media' => 1, 'alt' => ' ', 'decorative' => false]), Profile::Article, 'ALT-INF'];
        yield 'javascript link' => [$link('javascript:alert(1)'), Profile::Basic, 'target'];
        yield 'data link' => [$link('data:text/html,x'), Profile::Basic, 'target'];
        yield 'http link' => [$link('http://example.test/'), Profile::Basic, 'target'];
        yield 'relative link' => [$link('/p/6205/'), Profile::Basic, 'target'];
        yield 'quote in url' => [$link('https://example.test/"onmouseover=x'), Profile::Basic, 'target'];
        yield 'bad product slug' => [$link('product:62 05'), Profile::Basic, 'target'];
        yield 'link in link' => [$p([['type' => 'link', 'target' => 'section:a', 'children' => [['type' => 'link', 'target' => 'section:b', 'children' => self::text()]]]]), Profile::Basic, 'links cannot contain links'];
        yield 'unknown mark' => [$p([['type' => 'text', 'text' => 'x', 'marks' => ['underline']]]), Profile::Basic, 'marks'];
        yield 'mark order' => [$p([['type' => 'text', 'text' => 'x', 'marks' => ['em', 'strong']]]), Profile::Basic, 'marks'];
        yield 'style smuggled as a field' => [$p([['type' => 'text', 'text' => 'x', 'marks' => [], 'style' => 'color:red']]), Profile::Basic, 'unknown field "style"'];
        yield 'control character' => [$p([['type' => 'text', 'text' => "x\x07", 'marks' => []]]), Profile::Basic, 'control characters'];
        yield 'backtick in code' => [$p([['type' => 'code', 'text' => 'a`b']]), Profile::Basic, 'backtick'];
        yield 'empty paragraph' => [$p([]), Profile::Basic, 'text is required'];
    }

    #[DataProvider('invalid')]
    public function testInvalidDocumentsAreRejected(mixed $document, Profile $profile, string $expected): void
    {
        $errors = Schema::errors($document, $profile);
        self::assertNotSame([], $errors);
        self::assertStringContainsString($expected, implode("\n", $errors));
    }
}
