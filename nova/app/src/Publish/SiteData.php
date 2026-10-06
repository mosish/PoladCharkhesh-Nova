<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Catalogue\PublishedIndex;
use Nova\Core\Sql;
use Nova\Designation\Normaliser;
use PDO;
use RuntimeException;

/**
 * Everything a release renders, read from one database snapshot (step 8 runs
 * this inside a consistent-snapshot transaction). Plain typed arrays, so
 * templates, JSON and checks all read the same model.
 *
 * Model rules (D20/E-2, 05 §5, §11, §25):
 * - one Product = one page and one explorer row; its listed items are the base
 *   designation when base_listed is on, then each listed variant;
 * - every item carries only its own values, each with its own status and
 *   source; sources are lettered A, B, … per page in order of first use;
 * - an explorer d/D/B cell shows a value only when every listed item has it
 *   sourced and identical; "varies" when all have it and they differ; "—"
 *   when any is missing.
 *
 * @phpstan-type Value array{s: string, fa: string, en: string, v: string, n: ?float, u: string, st: string, src: ?string, core: bool, loc: ?string}
 * @phpstan-type Item array{kind: string, slug: string, anchor: string, designation: string, values: list<Value>, drawing: ?string}
 * @phpstan-type CoreKey array{code: string, fa: string, en: string}
 * @phpstan-type Family array{id: int, slug: string, glyph: string, fa: string, en: string, short_fa: string, short_en: string, def_fa: string, def_en: string, count: int, core: list<CoreKey>}
 * @phpstan-type Source array{key: string, label: string, url: string, checked: string}
 * @phpstan-type Column array{k: ?string, v: ?string, state: string}
 * @phpstan-type Product array{id: int, slug: string, designation: string, family: Family, base_listed: bool, verified: bool, is_sample: bool, show_in_explorer: bool, items: list<Item>, known_variants: list<string>, spellings: list<string>, sources: list<Source>, description: array{fa: ?array<array-key, mixed>, en: ?array<array-key, mixed>}, search: string, cols: list<Column>}
 * @phpstan-type Index array{products: list<array{id: string, designation: string, family: string, base_listed: bool, variants: list<array{id: string, designation: string, listed: bool}>}>, spellings: list<array{spelling: string, target: string}>, suffix_rules: array<string, list<string>>}
 */
final class SiteData
{
    public const array EXAMPLES = ['6205', '22220 E', 'UCP 208', '30208'];

    /**
     * @param list<Family> $families
     * @param list<Product> $products
     * @param Index $index
     * @param array<string, string> $contact
     * @param list<string> $examples
     */
    public function __construct(
        public readonly array $families,
        public readonly array $products,
        public readonly array $index,
        public readonly array $contact,
        public readonly array $examples,
        public readonly bool $samples,
    ) {}

    public static function load(PDO $pdo, bool $includeSamples, string $contactFile): self
    {
        $index = PublishedIndex::build($pdo, $includeSamples);
        $samples = $includeSamples ? 1 : 0;

        /** @var array<string, Family> $families */
        $families = [];
        foreach (Sql::rows($pdo, "SELECT f.*, (SELECT COUNT(*) FROM product p WHERE p.family_id = f.id AND p.status = 'published' AND (p.is_sample = 0 OR ? = 1)) AS published FROM family f ORDER BY f.sort, f.id", [$samples]) as $f) {
            $core = [];
            foreach (Sql::rows($pdo, 'SELECT code, label_fa, label_en FROM spec_key WHERE family_id = ? AND is_core = 1 ORDER BY sort, id', [(int) $f['id']]) as $k) {
                $core[] = ['code' => self::s($k['code']), 'fa' => self::s($k['label_fa']), 'en' => self::s($k['label_en'])];
            }
            $families[self::s($f['slug'])] = [
                'id' => (int) $f['id'], 'slug' => self::s($f['slug']), 'glyph' => self::s($f['glyph'] ?? 'g-ball'),
                'fa' => self::s($f['name_fa']), 'en' => self::s($f['name_en']), 'short_fa' => self::s($f['short_fa']), 'short_en' => self::s($f['short_en']),
                'def_fa' => self::s($f['definition_fa']), 'def_en' => self::s($f['definition_en']), 'count' => (int) $f['published'], 'core' => $core,
            ];
        }

        $products = [];
        foreach (Sql::rows($pdo, "SELECT * FROM product WHERE status = 'published' AND (is_sample = 0 OR ? = 1) ORDER BY id", [$samples]) as $p) {
            $products[] = self::product($pdo, $p, $families, $includeSamples);
        }

        $designations = [];
        foreach ($products as $p) {
            $designations[] = $p['designation'];
            foreach ($p['items'] as $item) {
                $designations[] = $item['designation'];
            }
        }

        return new self(array_values($families), $products, $index, self::contact($pdo, $contactFile), array_values(array_intersect(self::EXAMPLES, $designations)), $includeSamples);
    }

    /**
     * @param array<string, scalar|null> $p
     * @param array<string, Family> $families
     *
     * @return Product
     */
    private static function product(PDO $pdo, array $p, array $families, bool $includeSamples): array
    {
        $id = (int) $p['id'];
        $family = self::familyById($families, (int) $p['family_id']);
        /** @var array<int, string> $letters */
        $letters = [];
        /** @var array<string, Source> $sources */
        $sources = [];
        $sample = $includeSamples && (int) $p['is_sample'] === 1;
        $designation = self::s($p['designation']);

        $items = [];
        if ((int) $p['base_listed'] === 1) {
            $items[] = self::item($pdo, 'base', 'base', $designation, 'product_id', $id, $family, $letters, $sources);
        }
        $known = [];
        $confirmed = $sample ? '1 = 1' : 'relationship_confirmed_at IS NOT NULL';
        foreach (Sql::rows($pdo, "SELECT id, slug, designation, listed FROM product_variant WHERE product_id = ? AND status = 'published' AND {$confirmed} ORDER BY sort, id", [$id]) as $v) {
            if ((int) $v['listed'] === 1) {
                $items[] = self::item($pdo, 'variant', self::s($v['slug']), self::s($v['designation']), 'variant_id', (int) $v['id'], $family, $letters, $sources);
            } else {
                $known[] = self::s($v['designation']);
            }
        }
        $spellings = array_map(self::s(...), Sql::column($pdo, 'SELECT s.spelling FROM spelling s LEFT JOIN product_variant v ON v.id = s.variant_id WHERE (s.product_id = ? OR v.product_id = ?) AND (s.confirmed_at IS NOT NULL OR ? = 1) ORDER BY s.id', [$id, $id, $sample ? 1 : 0]));

        $searchKeys = [$designation, ...array_map(static fn(array $i): string => $i['designation'], $items), ...$known, ...$spellings, $family['fa'], $family['en']];
        $keys = array_values(array_unique(array_filter(array_map(Normaliser::key(...), $searchKeys), static fn(string $k): bool => $k !== '')));

        return [
            'id' => $id,
            'slug' => self::s($p['slug']),
            'designation' => $designation,
            'family' => $family,
            'base_listed' => (int) $p['base_listed'] === 1,
            'verified' => $p['verification'] === 'verified',
            'is_sample' => (int) $p['is_sample'] === 1,
            'show_in_explorer' => (int) $p['show_in_explorer'] === 1,
            'items' => $items,
            'known_variants' => $known,
            'spellings' => $spellings,
            'sources' => array_values($sources),
            'description' => ['fa' => self::document($p['description_fa'] ?? null), 'en' => self::document($p['description_en'] ?? null)],
            'search' => implode('|', $keys),
            'cols' => self::columns($items, $family),
        ];
    }

    /**
     * @param Family $family
     * @param array<int, string> $letters source id => letter
     * @param array<string, Source> $sources letter => source
     *
     * @return Item
     */
    private static function item(PDO $pdo, string $kind, string $slug, string $designation, string $column, int $id, array $family, array &$letters, array &$sources): array
    {
        $rows = Sql::rows($pdo, "SELECT v.value_text, v.value_num, v.unit, v.status, v.source_id, v.source_locator, v.checked_on, k.code, k.label_fa, k.label_en, k.is_core, k.unit AS key_unit, s.title AS source_title, s.url AS source_url FROM spec_value v JOIN spec_key k ON k.id = v.spec_key_id LEFT JOIN source s ON s.id = v.source_id WHERE v.{$column} = ? ORDER BY k.is_core DESC, k.sort, k.id", [$id]);
        $values = [];
        $dims = [];
        $allSourced = true;
        foreach ($rows as $r) {
            $key = null;
            if ($r['source_id'] !== null) {
                $sid = (int) $r['source_id'];
                if (!isset($letters[$sid])) {
                    $n = \count($letters);
                    $letters[$sid] = \chr(65 + $n % 26) . ($n >= 26 ? (string) intdiv($n, 26) : '');
                }
                $key = $letters[$sid];
                $checked = self::s($r['checked_on'] ?? '');
                $sources[$key] = ['key' => $key, 'label' => self::s($r['source_title']), 'url' => self::s($r['source_url'] ?? ''), 'checked' => max($checked, $sources[$key]['checked'] ?? '')];
            }
            $isCore = (int) $r['is_core'] === 1;
            $number = $r['value_num'] === null ? null : (float) $r['value_num'];
            $values[] = [
                's' => self::s($r['code']), 'fa' => self::s($r['label_fa']), 'en' => self::s($r['label_en']),
                'v' => self::s($r['value_text']), 'n' => $number, 'u' => self::s($r['unit'] ?? $r['key_unit'] ?? ''),
                'st' => $r['status'] === 'verified' ? 'ok' : 'pending', 'src' => $key, 'core' => $isCore,
                'loc' => $r['source_locator'] === null ? null : self::s($r['source_locator']),
            ];
            if ($isCore && $number !== null) {
                $dims[self::s($r['code'])] = $number;
                $allSourced = $allSourced && $r['source_id'] !== null;
            }
        }
        // Generated drawings use sourced dimensions only (05 §26).
        $drawing = $allSourced && $dims !== [] ? Drawing::svg($family['slug'], $dims, $designation . ' — ' . $family['en']) : null;

        return ['kind' => $kind, 'slug' => $slug, 'anchor' => $kind === 'base' ? 'base' : 'v-' . $slug, 'designation' => $designation, 'values' => $values, 'drawing' => $drawing];
    }

    /**
     * Explorer d/D/B cells: the family's first three core keys.
     *
     * @param list<Item> $items
     * @param Family $family
     *
     * @return list<Column>
     */
    private static function columns(array $items, array $family): array
    {
        $cols = [];
        foreach (\array_slice($family['core'], 0, 3) as $key) {
            $seen = [];
            $complete = $items !== [];
            foreach ($items as $item) {
                $found = null;
                foreach ($item['values'] as $v) {
                    if ($v['s'] === $key['code'] && $v['src'] !== null) {
                        $found = $v['v'];
                    }
                }
                if ($found === null) {
                    $complete = false;
                } else {
                    $seen[$found] = true;
                }
            }
            $state = !$complete ? 'none' : (\count($seen) === 1 ? 'value' : 'varies');
            $cols[] = ['k' => $key['code'], 'v' => $state === 'value' ? (string) array_key_first($seen) : null, 'state' => $state];
        }
        while (\count($cols) < 3) {
            $cols[] = ['k' => null, 'v' => null, 'state' => 'none'];
        }

        return $cols;
    }

    /**
     * @param array<string, Family> $families
     *
     * @return Family
     */
    private static function familyById(array $families, int $id): array
    {
        foreach ($families as $f) {
            if ($f['id'] === $id) {
                return $f;
            }
        }

        throw new RuntimeException("Unknown family {$id}.");
    }

    /** @return array<string, string> Phase 2 contact values, overridden by Company settings (company.*) */
    private static function contact(PDO $pdo, string $file): array
    {
        $contact = require $file;
        $out = [];
        foreach (\is_array($contact) ? $contact : [] as $key => $value) {
            $out[(string) $key] = self::s($value);
        }
        foreach (Sql::pairs($pdo, "SELECT SUBSTRING(setting_key, 9), value_text FROM setting WHERE setting_key LIKE 'company.%'") as $key => $value) {
            $out[(string) $key] = self::s($value);
        }

        return $out;
    }

    /** @return array<array-key, mixed>|null */
    private static function document(mixed $json): ?array
    {
        $doc = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($doc) ? $doc : null;
    }

    private static function s(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
