<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Content;

use Nova\Content\ContentService;
use Nova\Content\RichText\BlockSyntax;
use Nova\Content\RichText\RichText;
use Nova\Content\RichText\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockSyntaxTest extends TestCase
{
    public function testParagraphsAndListsBecomeABasicDocument(): void
    {
        $doc = BlockSyntax::parse("First **line**\ncontinues here.\n\n- one\n- two\n  - two a\n\n1. first\n2. second\n\nThe `6205` [page](product:qz-1).");
        self::assertNotNull($doc);
        self::assertSame([], Schema::errors($doc, ContentService::PRODUCT_DESCRIPTION));
        self::assertSame(['paragraph', 'list', 'list', 'paragraph'], array_column($doc['blocks'], 'type'));
        self::assertSame([false, true], [$doc['blocks'][1]['ordered'], $doc['blocks'][2]['ordered']]);
        self::assertSame('First **line** continues here.', BlockSyntax::format(RichText::document([$doc['blocks'][0]])));
    }

    public function testNothingTypedBecomesMarkup(): void
    {
        $doc = BlockSyntax::parse('<script>alert(1)</script> <b>x</b>');
        self::assertNotNull($doc);
        self::assertSame([], Schema::errors($doc, ContentService::PRODUCT_DESCRIPTION));
        self::assertSame('<script>alert(1)</script> <b>x</b>', BlockSyntax::format($doc));
    }

    public function testEmptyTextClearsTheField(): void
    {
        self::assertNull(BlockSyntax::parse(''));
        self::assertNull(BlockSyntax::parse("\n  \n\t\n"));
    }

    /** @return iterable<string, array{string}> */
    public static function roundTrips(): iterable
    {
        yield 'paragraphs' => ["One.\n\nTwo with *emphasis*."];
        yield 'a paragraph that looks like an item' => ['\\- not a list'];
        yield 'numbered-looking paragraph' => ['\\3. not a list either'];
        yield 'unordered with nesting' => ["- a\n  - a1\n  - a2\n- b"];
        yield 'ordered' => ["1. x\n2. y"];
        yield 'two lists in a row' => ["- a\n\n1. b"];
        yield 'persian' => ["بلبرینگ `6205` برای **موتور**.\n\n- یک\n- دو"];
    }

    #[DataProvider('roundTrips')]
    public function testFormatThenParseIsTheIdentity(string $text): void
    {
        $doc = BlockSyntax::parse($text);
        self::assertNotNull($doc);
        self::assertSame([], Schema::errors($doc, ContentService::PRODUCT_DESCRIPTION));
        $formatted = BlockSyntax::format($doc);
        self::assertNotNull($formatted);
        self::assertSame($doc, BlockSyntax::parse($formatted));
        self::assertSame($text, $formatted);
    }

    public function testBlocksThisFormCannotShowAreReportedNotDropped(): void
    {
        $doc = RichText::document([['type' => 'heading', 'level' => 2, 'content' => [['type' => 'text', 'text' => 'H']]]]);
        self::assertNull(BlockSyntax::format($doc));
    }
}
