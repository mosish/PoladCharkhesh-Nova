<?php

declare(strict_types=1);

namespace Nova\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The development runtime must offer what 05 §2 requires of any Nova host,
 * so code written locally cannot quietly depend on less (or more).
 */
final class RuntimeTest extends TestCase
{
    public function testPhpIsTheRecordedLocalBaseline(): void
    {
        // Update docs/local-baseline.md together with this assertion.
        self::assertSame('8.4', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
    }

    /** @return iterable<string, array{string}> */
    public static function extensions(): iterable
    {
        $required = ['pdo_mysql', 'mbstring', 'intl', 'fileinfo', 'zlib', 'openssl', 'json', 'ctype', 'session', 'filter', 'hash', 'gd'];
        $recommended = ['zip', 'curl', 'exif', 'sodium', 'dom', 'libxml', 'Zend OPcache'];
        foreach ([...$required, ...$recommended] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('extensions')]
    public function testExtensionIsLoaded(string $name): void
    {
        self::assertTrue(\extension_loaded($name), "PHP extension {$name} is missing");
    }

    public function testGdCanEncodeWebp(): void
    {
        self::assertTrue(gd_info()['WebP Support']);

        $image = imagecreatetruecolor(4, 4);
        self::assertNotFalse($image);
        ob_start();
        imagewebp($image);
        $bytes = (string) ob_get_clean();
        self::assertStringStartsWith('RIFF', $bytes);
    }

    public function testDefaultTimezoneIsUtc(): void
    {
        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testTehranTimezoneDataHasNoDaylightSaving(): void
    {
        // Iran abolished daylight saving from 2023; mid-summer must be +03:30.
        $summer = new DateTimeImmutable('2026-07-01 12:00', new DateTimeZone('Asia/Tehran'));
        self::assertSame(12600, $summer->getOffset());
    }

    public function testIntlProvidesThePersianCalendar(): void
    {
        $formatter = new IntlDateFormatter(
            'en_US@calendar=persian',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'Asia/Tehran',
            IntlDateFormatter::TRADITIONAL,
            'y-MM-dd',
        );
        // Nowruz 1403 fell on 2024-03-20.
        $nowruz = new DateTimeImmutable('2024-03-20 12:00', new DateTimeZone('Asia/Tehran'));
        self::assertSame('1403-01-01', $formatter->format($nowruz));
    }
}
