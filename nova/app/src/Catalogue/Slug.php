<?php

declare(strict_types=1);

namespace Nova\Catalogue;

/**
 * Public URL slugs (`/p/<slug>/`, `#v-<slug>`), derived from the designation in
 * the Phase 2 style ("NU 205" → nu-205, "TC 25×42×7" → tc-25x42x7). A slug is
 * editable until the first publish and frozen afterwards (public identity).
 */
final class Slug
{
    public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public static function fromDesignation(string $designation): string
    {
        $text = strtr($designation, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4', '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
            '×' => 'x',
        ]);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');

        return substr($slug, 0, 64);
    }

    public static function assertValid(string $slug): void
    {
        if (\strlen($slug) > 64 || preg_match(self::PATTERN, $slug) !== 1) {
            throw CatalogueError::invalid("\"{$slug}\" is not a valid slug (lower-case a–z, 0–9 and single hyphens).");
        }
    }
}
