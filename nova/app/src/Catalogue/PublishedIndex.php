<?php

declare(strict_types=1);

namespace Nova\Catalogue;

use Nova\Core\Sql;
use PDO;

/**
 * Builds the matcher's designation index (docs/designation-matching.md §3)
 * from the working catalogue, as Publish and the inquiry endpoint see it:
 * published Products, their published variants and spellings, and only
 * confirmed relationships. Ids are public (`p:<slug>`, `v:<slug>`), never
 * internal numbers. No suffix rules are approved (V-1), so suffix_rules is
 * empty.
 *
 * `$includeSamples` is true only for development builds: the labelled Phase 2
 * sample is then included, and its recorded (unconfirmed) relationships count.
 */
final class PublishedIndex
{
    /** @return array{products: list<array{id: string, designation: string, family: string, base_listed: bool, variants: list<array{id: string, designation: string, listed: bool}>}>, spellings: list<array{spelling: string, target: string}>, suffix_rules: array<string, list<string>>} */
    public static function build(PDO $pdo, bool $includeSamples): array
    {
        $samples = $includeSamples ? 1 : 0;
        $products = [];
        $productIds = [];
        foreach (Sql::rows($pdo, "SELECT p.id, p.slug, p.designation, p.base_listed, p.is_sample, f.slug AS family FROM product p JOIN family f ON f.id = p.family_id WHERE p.status = 'published' AND (p.is_sample = 0 OR ? = 1) ORDER BY p.id", [$samples]) as $p) {
            $products[(int) $p['id']] = ['id' => 'p:' . $p['slug'], 'designation' => (string) $p['designation'], 'family' => (string) $p['family'], 'base_listed' => (int) $p['base_listed'] === 1, 'variants' => []];
            $productIds[(int) $p['id']] = (int) $p['is_sample'] === 1;
        }

        $variantIds = [];
        $variantProduct = [];
        foreach (Sql::rows($pdo, "SELECT v.id, v.product_id, v.slug, v.designation, v.listed, v.relationship_confirmed_at FROM product_variant v WHERE v.status = 'published' ORDER BY v.product_id, v.sort, v.id") as $v) {
            $productId = (int) $v['product_id'];
            if (!isset($products[$productId])) {
                continue;
            }
            if ($v['relationship_confirmed_at'] === null && !($includeSamples && $productIds[$productId])) {
                continue;
            }
            $products[$productId]['variants'][] = ['id' => 'v:' . $v['slug'], 'designation' => (string) $v['designation'], 'listed' => (int) $v['listed'] === 1];
            $variantIds[(int) $v['id']] = 'v:' . $v['slug'];
            $variantProduct[(int) $v['id']] = $productId;
        }

        $spellings = [];
        foreach (Sql::rows($pdo, 'SELECT spelling, product_id, variant_id, confirmed_at FROM spelling ORDER BY id') as $s) {
            $target = $s['product_id'] !== null ? ($products[(int) $s['product_id']]['id'] ?? null) : ($variantIds[(int) $s['variant_id']] ?? null);
            $owner = $s['product_id'] !== null ? (int) $s['product_id'] : ($variantProduct[(int) $s['variant_id']] ?? 0);
            $sample = $includeSamples && ($productIds[$owner] ?? false);
            if ($target === null || ($s['confirmed_at'] === null && !$sample)) {
                continue;
            }
            $spellings[] = ['spelling' => (string) $s['spelling'], 'target' => $target];
        }

        return ['products' => array_values($products), 'spellings' => $spellings, 'suffix_rules' => []];
    }
}
