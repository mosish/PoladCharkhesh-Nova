<?php

declare(strict_types=1);

namespace Nova\Dev;

use Nova\Core\Environment;
use Nova\Core\Sql;
use Nova\Designation\Normaliser;
use PDO;
use RuntimeException;

/**
 * DEVELOPMENT/TEST FIXTURE for browser tests: a few invented "QZ" designations
 * that exercise the match categories the Phase 2 sample cannot (unlisted
 * variant, spelling, related with one / many / zero listed items). They are
 * not bearing designations, carry no values, and are is_sample = 1, so no
 * production build can ever contain them. Loaded only by `dev:e2e`.
 */
final class SyntheticCatalogue
{
    /** [slug, designation, base_listed, [[variant, listed], ...], [spelling => target variant designation]] */
    public const array PRODUCTS = [
        ['qz-200', 'QZ 200', 0, [['QZ 200-K1', 1], ['QZ 200-KK', 1], ['QZ 200-K2', 0]], ['QZ 200 KAY KAY' => 'QZ 200-KK']],
        ['qz-300', 'QZ 300', 0, [], []],
        ['qz-500', 'QZ 500', 0, [['QZ 500 E', 1]], []],
    ];

    public static function load(PDO $pdo, Environment $environment): void
    {
        if ($environment !== Environment::Development) {
            throw new RuntimeException('Synthetic fixtures load only in development.');
        }
        $family = Sql::int($pdo, "SELECT id FROM family WHERE slug = 'ball'");
        if ($family === 0) {
            throw new RuntimeException('Load the sample catalogue first.');
        }
        $note = 'SYNTHETIC test designation (invented; not a bearing): browser-test fixture only.';
        foreach (self::PRODUCTS as [$slug, $designation, $baseListed, $variants, $spellings]) {
            Sql::run($pdo, "INSERT INTO product (family_id, designation, slug, base_listed, status, review_note, is_sample) VALUES (?, ?, ?, ?, 'published', ?, 1)", [$family, $designation, $slug, $baseListed, $note]);
            $productId = (int) $pdo->lastInsertId();
            Sql::run($pdo, 'INSERT INTO designation_key (norm_key, product_id) VALUES (?, ?)', [Normaliser::key($designation), $productId]);
            $variantIds = [];
            foreach ($variants as $sort => [$variant, $listed]) {
                Sql::run($pdo, "INSERT INTO product_variant (product_id, designation, slug, listed, status, sort, notes) VALUES (?, ?, ?, ?, 'published', ?, ?)", [$productId, $variant, strtolower(str_replace(' ', '-', $variant)), $listed, $sort, $note]);
                $variantIds[$variant] = (int) $pdo->lastInsertId();
                Sql::run($pdo, 'INSERT INTO designation_key (norm_key, variant_id) VALUES (?, ?)', [Normaliser::key($variant), $variantIds[$variant]]);
            }
            foreach ($spellings as $spelling => $target) {
                $targetId = $variantIds[$target] ?? throw new RuntimeException("Unknown synthetic variant {$target}.");
                Sql::run($pdo, 'INSERT INTO spelling (variant_id, spelling, note) VALUES (?, ?, ?)', [$targetId, $spelling, $note]);
                Sql::run($pdo, 'INSERT INTO designation_key (norm_key, spelling_id) VALUES (?, ?)', [Normaliser::key($spelling), (int) $pdo->lastInsertId()]);
            }
        }
    }
}
