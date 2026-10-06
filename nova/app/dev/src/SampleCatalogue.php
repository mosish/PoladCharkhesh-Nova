<?php

declare(strict_types=1);

namespace Nova\Dev;

use Nova\Core\Environment;
use Nova\Core\Sql;
use Nova\Designation\Normaliser;
use PDO;
use RuntimeException;

/**
 * DEVELOPMENT/TEST FIXTURE: the Phase 2 prototype sample, copied verbatim into
 * app/dev/fixtures/phase2-sample.json, loaded into the schema.
 *
 * - Every product is is_sample = 1; Publish refuses samples outside
 *   development, so they are never presented as production data.
 * - Families, designations, variants and listing are as the sample recorded
 *   them. 6305-2RS and 22220 E were standalone rows, so they are standalone
 *   Products flagged for relationship review (05 §9): nothing is inferred.
 * - The sample's variants carry no relationship confirmation (none exists);
 *   development builds treat sample relationships as recorded, production
 *   builds refuse samples altogether.
 * - The sample's spellings normalise to their own designation's key, so they
 *   are not recorded (they would be redundant duplicate keys).
 * - Spec keys use exactly the sample's symbols per family (V-6 leaves the real
 *   per-family core keys open). Values load as PENDING, never verified, on the
 *   designation the sample gave them; variants receive no values (no copying).
 */
final class SampleCatalogue
{
    private const string REVIEW_NOTE = 'Phase 2 sample row: any relationship to %s is unverified (V-1); kept standalone.';

    /** Standalone sample rows whose possible base designation is unverified. */
    private const array REVIEW = ['6305-2RS' => '6305', '22220 E' => '22220'];

    /** @return array<string, mixed> */
    public static function data(): array
    {
        $json = file_get_contents(\dirname(__DIR__) . '/fixtures/phase2-sample.json');
        $data = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new RuntimeException('Invalid sample fixture.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    public static function load(PDO $pdo, Environment $environment): void
    {
        if ($environment !== Environment::Development) {
            throw new RuntimeException("Sample fixtures load only in development (environment is {$environment->value}).");
        }
        if (Sql::int($pdo, 'SELECT COUNT(*) FROM product') > 0) {
            throw new RuntimeException('The catalogue is not empty; sample fixtures load only into an empty catalogue.');
        }

        $data = self::data();
        /** @var list<array{slug: string, glyph: string, fa: string, en: string, short_fa: string, short_en: string, def_fa: string, def_en: string}> $families */
        $families = $data['families'];
        /** @var list<array{slug: string, code: string, family: string, dims: array<string, int|float>, sources: array<string, array{label: string, url: string, checked: string}>, dim_src: array<string, ?string>, variants: list<array{code: string}>}> $products */
        $products = $data['products'];
        /** @var array<string, array{string, string}> $labels */
        $labels = $data['dim_labels'];
        /** @var array<string, array<string, array{string, string}>> $familyLabels */
        $familyLabels = $data['dim_labels_by_family'];

        $pdo->beginTransaction();
        try {
            $familyIds = [];
            foreach ($families as $i => $f) {
                Sql::run($pdo, 'INSERT INTO family (slug, glyph, name_fa, name_en, short_fa, short_en, definition_fa, definition_en, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$f['slug'], $f['glyph'], $f['fa'], $f['en'], $f['short_fa'], $f['short_en'], $f['def_fa'], $f['def_en'], $i + 1]);
                $familyIds[$f['slug']] = (int) $pdo->lastInsertId();
            }

            // Spec keys: the sample's symbols per family, in first-seen order.
            $specKeys = [];
            foreach ($products as $p) {
                foreach (array_keys($p['dims']) as $symbol) {
                    $symbol = (string) $symbol;
                    if (isset($specKeys[$p['family']][$symbol])) {
                        continue;
                    }
                    [$fa, $en] = $familyLabels[$p['family']][$symbol] ?? $labels[$symbol];
                    Sql::run($pdo, "INSERT INTO spec_key (family_id, code, label_fa, label_en, unit, value_type, is_core, sort) VALUES (?, ?, ?, ?, 'mm', 'number', 1, ?)", [$familyIds[$p['family']], $symbol, $fa, $en, \count($specKeys[$p['family']] ?? []) + 1]);
                    $specKeys[$p['family']][$symbol] = (int) $pdo->lastInsertId();
                }
            }

            foreach ($products as $p) {
                $review = isset(self::REVIEW[$p['code']]) ? \sprintf(self::REVIEW_NOTE, self::REVIEW[$p['code']]) : null;
                Sql::run($pdo, "INSERT INTO product (family_id, designation, slug, base_listed, status, relationship_review, review_note, is_sample) VALUES (?, ?, ?, 1, 'published', ?, ?, 1)", [$familyIds[$p['family']], $p['code'], $p['slug'], $review === null ? 0 : 1, $review]);
                $productId = (int) $pdo->lastInsertId();
                Sql::run($pdo, 'INSERT INTO designation_key (norm_key, product_id) VALUES (?, ?)', [Normaliser::key($p['code']), $productId]);

                foreach ($p['variants'] as $sort => $v) {
                    Sql::run($pdo, "INSERT INTO product_variant (product_id, designation, slug, listed, status, sort) VALUES (?, ?, ?, 1, 'published', ?)", [$productId, $v['code'], strtolower($v['code']), $sort]);
                    Sql::run($pdo, 'INSERT INTO designation_key (norm_key, variant_id) VALUES (?, ?)', [Normaliser::key($v['code']), (int) $pdo->lastInsertId()]);
                }

                $sourceIds = [];
                foreach ($p['sources'] as $letter => $s) {
                    Sql::run($pdo, 'INSERT INTO source (title, url, note) VALUES (?, ?, ?)', [$s['label'], $s['url'], 'Phase 2 sample source (checked for layout purposes; not a manufacturer catalogue).']);
                    $sourceIds[$letter] = [(int) $pdo->lastInsertId(), $s['checked']];
                }
                foreach ($p['dims'] as $symbol => $value) {
                    $source = $p['dim_src'][$symbol] ?? null;
                    [$sourceId, $checked] = $source !== null ? $sourceIds[$source] : [null, null];
                    $text = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
                    Sql::run($pdo, "INSERT INTO spec_value (product_id, spec_key_id, value_text, value_num, unit, status, source_id, checked_on) VALUES (?, ?, ?, ?, 'mm', 'pending', ?, ?)", [$productId, $specKeys[$p['family']][(string) $symbol], $text, $text, $sourceId, $checked]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }
}
