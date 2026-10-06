<?php

declare(strict_types=1);

namespace Nova\Designation;

use InvalidArgumentException;

/**
 * Designation keys (docs/designation-matching.md §2). Mirrored exactly by
 * assets-src/js/designation.js; both run tests/fixtures/designation/corpus.json.
 */
final class Normaliser
{
    /** Steps 1, 2 and 5: digits (Persian, Arabic-Indic, fullwidth), Arabic letter forms, the multiplication sign. */
    private const array MAP = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
        '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        'ي' => 'ی', 'ك' => 'ک',
        '×' => 'x',
    ];

    /** Whitespace (step 6a); also what RequestedDesignation trims. */
    public const string WHITESPACE = '\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    /** Step 3 (invisible marks) and step 6 (whitespace, hyphens and dashes, underscore, full stop). */
    private const string REMOVE = '/[\x{00AD}\x{061C}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}'
        . self::WHITESPACE
        . '\x{002D}\x{2010}-\x{2015}\x{2212}\x{FE58}\x{FE63}\x{FF0D}\x{005F}\x{002E}]/u';

    public static function key(string $text): string
    {
        self::assertUtf8($text);

        // strtolower is ASCII-only since PHP 8.2 (step 4); "/" is never touched (E-19).
        $key = preg_replace(self::REMOVE, '', strtolower(strtr($text, self::MAP)));
        if ($key === null) {
            throw new InvalidArgumentException('Designation could not be normalised.');
        }

        return $key;
    }

    public static function assertUtf8(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Designation text is not valid UTF-8.');
        }
    }
}
