<?php

declare(strict_types=1);

namespace Nova\Dev;

use Nova\Core\Environment;
use Nova\Core\Sql;
use Nova\Designation\Normaliser;
use PDO;
use RuntimeException;

/**
 * DEVELOPMENT/TEST FIXTURE: the Phase 2 prototype sample
 * (design/phase2-v2-corrections/src/catalogue.json) loaded into schema v1.
 *
 * - Every product is marked is_sample = 1; Publish refuses samples outside
 *   development, so they can never be presented as production data.
 * - Families, designations, variants and listing are exactly as the sample
 *   recorded them. 6305-2RS and 22220 E were standalone rows there, so they are
 *   standalone Products flagged for relationship review (05 §9): no relationship
 *   to 6305 or 22220 is inferred.
 * - The sample's spellings normalise to their own designation's key and are
 *   therefore not recorded (they would be redundant duplicate keys).
 * - No specification values are loaded here.
 */
final class SampleCatalogue
{
    public const array FAMILIES = [
        ['ball', 'g-ball', 'بلبرینگ ساچمه‌ای', 'Ball bearings', 'ساچمه‌ای', 'Ball', 'پرکاربردترین نوع؛ بار شعاعی و بار محوری متوسط در سرعت بالا.', 'The most widely used type: radial and moderate axial loads at high speed.'],
        ['tapered', 'g-tapered', 'رولبرینگ مخروطی', 'Tapered roller bearings', 'مخروطی', 'Tapered', 'بار ترکیبی شعاعی و محوری؛ معمولاً جفتی نصب می‌شود.', 'Combined radial and axial loads; usually mounted in pairs.'],
        ['spherical', 'g-spherical', 'رولبرینگ بشکه‌ای', 'Spherical roller bearings', 'بشکه‌ای', 'Spherical', 'بارهای سنگین، همراه با تحمل ناهمراستایی شفت.', 'Heavy loads, while accommodating shaft misalignment.'],
        ['cylindrical', 'g-cylindrical', 'رولبرینگ استوانه‌ای', 'Cylindrical roller bearings', 'استوانه‌ای', 'Cylindrical', 'ظرفیت بار شعاعی بالا؛ برخی طرح‌ها جابه‌جایی محوری شفت را می‌پذیرند.', 'High radial capacity; some designs allow axial shaft movement.'],
        ['needle', 'g-needle', 'رولبرینگ سوزنی', 'Needle roller bearings', 'سوزنی', 'Needle', 'مقطع بسیار کم برای جاهای تنگ.', 'A very low cross-section for tight spaces.'],
        ['thrust', 'g-thrust', 'بلبرینگ کف‌گرد', 'Thrust bearings', 'کف‌گرد', 'Thrust', 'ویژه بار محوری.', 'Built for axial loads.'],
        ['unit', 'g-unit', 'یاتاقان آماده نصب', 'Bearing units', 'یاتاقان', 'Units', 'بلبرینگ و پوسته در یک واحد، آماده نصب روی شاسی.', 'Bearing and housing as one unit, ready to bolt down.'],
        ['housing', 'g-housing', 'پوسته یاتاقان', 'Bearing housings', 'پوسته', 'Housings', 'محفظه‌ای که بلبرینگ را روی ماشین نگه می‌دارد و آب‌بندی می‌کند.', 'Holds and seals the bearing on the machine.'],
        ['seal', 'g-seal', 'کاسه‌نمد صنعتی', 'Oil seals', 'کاسه‌نمد', 'Seals', 'جلوی نشت روانکار و ورود آلودگی را می‌گیرد.', 'Keeps lubricant in and contamination out.'],
        ['lubricant', 'g-lubricant', 'روانکار صنعتی', 'Industrial lubricants', 'روانکار', 'Lubricants', 'گریس و روغن متناسب با بار، سرعت و دمای کار.', 'Grease and oil matched to load, speed and temperature.'],
    ];

    /** [slug, designation, family, variants, relationship-review note] */
    public const array PRODUCTS = [
        ['6200', '6200', 'ball', ['6200-2RS', '6200-ZZ'], null],
        ['6201', '6201', 'ball', ['6201-2RS', '6201-ZZ'], null],
        ['6205', '6205', 'ball', ['6205-2RS', '6205-ZZ'], null],
        ['6206', '6206', 'ball', ['6206-2RS', '6206-ZZ'], null],
        ['6207', '6207', 'ball', ['6207-2RS', '6207-ZZ'], null],
        ['6305-2rs', '6305-2RS', 'ball', [], 'Phase 2 sample row: any relationship to 6305 is unverified (V-1); kept standalone.'],
        ['30208', '30208', 'tapered', [], null],
        ['22220-e', '22220 E', 'spherical', [], 'Phase 2 sample row: any relationship to 22220 is unverified (V-1); kept standalone.'],
        ['nu-205', 'NU 205', 'cylindrical', [], null],
        ['hk-2520', 'HK 2520', 'needle', [], null],
        ['51105', '51105', 'thrust', [], null],
        ['ucp-208', 'UCP 208', 'unit', [], null],
        ['tc-25x42x7', 'TC 25×42×7', 'seal', [], null],
    ];

    public static function load(PDO $pdo, Environment $environment): void
    {
        if ($environment !== Environment::Development) {
            throw new RuntimeException("Sample fixtures load only in development (environment is {$environment->value}).");
        }
        if ((int) Sql::scalar($pdo, 'SELECT COUNT(*) FROM product') > 0) {
            throw new RuntimeException('The catalogue is not empty; sample fixtures load only into an empty catalogue.');
        }

        $pdo->beginTransaction();
        try {
            $familyIds = [];
            $family = $pdo->prepare('INSERT INTO family (slug, glyph, name_fa, name_en, short_fa, short_en, definition_fa, definition_en, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach (self::FAMILIES as $i => $row) {
                $family->execute([...$row, $i + 1]);
                $familyIds[$row[0]] = (int) $pdo->lastInsertId();
            }

            $product = $pdo->prepare("INSERT INTO product (family_id, designation, slug, base_listed, status, relationship_review, review_note, is_sample) VALUES (?, ?, ?, 1, 'published', ?, ?, 1)");
            $variant = $pdo->prepare("INSERT INTO product_variant (product_id, designation, slug, listed, status, sort) VALUES (?, ?, ?, 1, 'published', ?)");
            $productKey = $pdo->prepare('INSERT INTO designation_key (norm_key, product_id) VALUES (?, ?)');
            $variantKey = $pdo->prepare('INSERT INTO designation_key (norm_key, variant_id) VALUES (?, ?)');
            foreach (self::PRODUCTS as [$slug, $designation, $familySlug, $variants, $review]) {
                $product->execute([$familyIds[$familySlug], $designation, $slug, $review === null ? 0 : 1, $review]);
                $productId = (int) $pdo->lastInsertId();
                $productKey->execute([Normaliser::key($designation), $productId]);
                foreach ($variants as $sort => $variantDesignation) {
                    $variant->execute([$productId, $variantDesignation, strtolower($variantDesignation), $sort]);
                    $variantKey->execute([Normaliser::key($variantDesignation), (int) $pdo->lastInsertId()]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }
}
