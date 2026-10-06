<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Publish;

use Nova\Publish\Drawing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DrawingTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, float>}> dimension sets in the sample's symbols */
    public static function families(): iterable
    {
        yield 'ball' => ['ball', ['d' => 25, 'D' => 52, 'B' => 15]];
        yield 'cylindrical' => ['cylindrical', ['d' => 25, 'D' => 52, 'B' => 15]];
        yield 'tapered' => ['tapered', ['d' => 40, 'D' => 80, 'T' => 19.75]];
        yield 'spherical' => ['spherical', ['d' => 100, 'D' => 180, 'B' => 46]];
        yield 'needle' => ['needle', ['Fw' => 25, 'D' => 32, 'C' => 20]];
        yield 'thrust' => ['thrust', ['d' => 25, 'D' => 42, 'H' => 11]];
        yield 'seal' => ['seal', ['d' => 25, 'D' => 42, 'b' => 7]];
        yield 'unit' => ['unit', ['d' => 40]];
    }

    /** @param array<string, float> $dims */
    #[DataProvider('families')]
    public function testDrawsEveryFamilyFromItsDimensions(string $family, array $dims): void
    {
        $svg = Drawing::svg($family, $dims, 'X — label');
        self::assertNotNull($svg);
        self::assertStringStartsWith('<svg class="dwg-svg"', $svg);
        self::assertStringContainsString('Ø' . Drawing::fmt($dims['d'] ?? $dims['Fw']), $svg);
        self::assertSame(1, preg_match_all('/<svg/', $svg), 'no nested or foreign markup');
        self::assertDoesNotMatchRegularExpression('/NAN|INF|-?nan/i', $svg);
    }

    public function testNothingIsDrawnWithoutTheNeededDimensions(): void
    {
        self::assertNull(Drawing::svg('ball', ['d' => 25, 'D' => 52], 'x'));
        self::assertNull(Drawing::svg('ball', ['d' => 52, 'D' => 25, 'B' => 9], 'x'), 'impossible geometry');
        self::assertNull(Drawing::svg('unit', ['D' => 40], 'x'));
        self::assertNull(Drawing::svg('lubricant', ['d' => 1, 'D' => 2, 'B' => 3], 'x'));
    }

    public function testTheLabelIsEscaped(): void
    {
        $svg = (string) Drawing::svg('ball', ['d' => 25, 'D' => 52, 'B' => 15], '<script>"x"</script>');
        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('aria-label="&lt;script&gt;&quot;x&quot;&lt;/script&gt;"', $svg);
    }

    public function testValuesAreWrittenLikeThePrototype(): void
    {
        self::assertSame('19.75', Drawing::fmt(19.75));
        self::assertSame('25', Drawing::fmt(25.0));
        self::assertSame('0.5', Drawing::fmt(0.5));
    }
}
