<?php

declare(strict_types=1);

namespace Nova\Admin;

use Nova\Audit\AuditLog;
use Nova\Catalogue\CatalogueError;
use Nova\Catalogue\ProductChanges;
use Nova\Catalogue\ProductService;
use Nova\Catalogue\RelationshipReview;
use Nova\Catalogue\VariantChanges;
use Nova\Content\ContentService;
use Nova\Content\RichText\BlockSyntax;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Designation\Normaliser;
use Nova\Http\Request;
use Nova\Provenance\DesignationRef;
use Nova\Provenance\PageVerification;
use Nova\Provenance\SourceService;
use Nova\Provenance\SpecValueService;
use Nova\Provenance\ValueInput;
use Nova\Publish\SiteBuilder;
use Nova\Security\Actor;
use PDO;
use Throwable;

/**
 * The Product/variant vertical slice of the Admin (05 §6, frozen A2/A3): the
 * list with data health, and the editor (identity, base_listed, variants,
 * spellings, per-value provenance, the description, publication and the
 * Owner-only verification). Reads are plain queries; every change goes
 * through the domain services, which own validation, optimistic locking,
 * Owner-only rules and the audit trail.
 */
final readonly class ProductAdmin
{
    private AuditLog $audit;
    private ProductService $products;

    public function __construct(private PDO $pdo)
    {
        $this->audit = new AuditLog($pdo);
        $this->products = new ProductService($pdo, $this->audit);
    }

    // ---- A2

    /** @return list<array<string, mixed>> */
    public function list(string $search, string $status): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (\in_array($status, ProductService::STATUSES, true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if (trim($search) !== '') {
            // The same normaliser as the public search and the Publish Engine (A2 note 1).
            $key = self::key($search);
            if ($key === '') {
                return [];
            }
            $where[] = 'p.id IN (SELECT COALESCE(k.product_id, v.product_id, s.product_id, sv.product_id) FROM designation_key k LEFT JOIN product_variant v ON v.id = k.variant_id LEFT JOIN spelling s ON s.id = k.spelling_id LEFT JOIN product_variant sv ON sv.id = s.variant_id WHERE k.norm_key LIKE ?)';
            $params[] = addcslashes($key, '%_\\') . '%';
        }
        $rows = Sql::rows($this->pdo, 'SELECT p.*, f.name_fa AS family_fa, (SELECT COUNT(*) FROM product_variant v WHERE v.product_id = p.id) AS variants FROM product p JOIN family f ON f.id = p.family_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.designation, p.id', $params);
        $out = [];
        foreach ($rows as $p) {
            $id = (int) $p['id'];
            $core = Sql::pairs($this->pdo, 'SELECT id, code FROM spec_key WHERE family_id = ? AND is_core = 1 ORDER BY sort, id', [(int) $p['family_id']]);
            $base = Sql::rows($this->pdo, 'SELECT spec_key_id, value_text, source_id FROM spec_value WHERE product_id = ?', [$id]);
            $byKey = [];
            foreach ($base as $v) {
                $byKey[(int) $v['spec_key_id']] = $v;
            }
            $dims = [];
            foreach ($core as $keyId => $code) {
                $dims[] = ['code' => (string) $code, 'value' => isset($byKey[$keyId]) ? (string) $byKey[$keyId]['value_text'] : null];
            }
            [$sourced, $required] = $this->sourceCoverage($id, array_keys($core), (int) $p['base_listed'] === 1);
            $out[] = [
                'id' => $id, 'designation' => (string) $p['designation'], 'family' => (string) $p['family_fa'], 'status' => (string) $p['status'],
                'verification' => (string) $p['verification'], 'explorer' => (int) $p['show_in_explorer'] === 1, 'sample' => (int) $p['is_sample'] === 1,
                'review' => (int) $p['relationship_review'] === 1, 'variants' => (int) $p['variants'], 'dims' => $dims,
                'sourced' => $sourced, 'required' => $required, 'has_fa' => $p['description_fa'] !== null, 'has_en' => $p['description_en'] !== null,
                'updated_at' => (string) $p['updated_at'],
            ];
        }

        return $out;
    }

    /** @return array<string, int> status => count */
    public function counts(): array
    {
        $out = [];
        foreach (Sql::pairs($this->pdo, 'SELECT status, COUNT(*) FROM product GROUP BY status') as $status => $n) {
            $out[(string) $status] = (int) $n;
        }

        return $out;
    }

    /** @return list<array{id: int, name: string}> */
    public function families(): array
    {
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name_fa']], Sql::rows($this->pdo, 'SELECT id, name_fa FROM family ORDER BY sort, id'));
    }

    // ---- A3

    /** @return array<string, mixed>|null */
    public function editor(int $id): ?array
    {
        $p = Sql::row($this->pdo, 'SELECT p.*, f.name_fa AS family_fa, f.slug AS family_slug FROM product p JOIN family f ON f.id = p.family_id WHERE p.id = ?', [$id]);
        if ($p === null) {
            return null;
        }
        $keys = array_map(static fn(array $k): array => [
            'id' => (int) $k['id'], 'code' => (string) $k['code'], 'label' => (string) $k['label_fa'], 'unit' => $k['unit'] === null ? '' : (string) $k['unit'],
            'type' => (string) $k['value_type'], 'core' => (int) $k['is_core'] === 1,
        ], Sql::rows($this->pdo, 'SELECT * FROM spec_key WHERE family_id = ? ORDER BY is_core DESC, sort, id', [(int) $p['family_id']]));

        $items = [['kind' => 'base', 'ref' => 'base', 'id' => $id, 'designation' => (string) $p['designation'], 'listed' => (int) $p['base_listed'] === 1, 'values' => $this->values('product_id', $id)]];
        foreach (Sql::rows($this->pdo, 'SELECT v.*, s.title AS relationship_source FROM product_variant v LEFT JOIN source s ON s.id = v.relationship_source_id WHERE v.product_id = ? ORDER BY v.sort, v.id', [$id]) as $v) {
            $items[] = [
                'kind' => 'variant', 'ref' => 'v' . (int) $v['id'], 'id' => (int) $v['id'], 'designation' => (string) $v['designation'], 'listed' => (int) $v['listed'] === 1,
                'status' => (string) $v['status'], 'verification' => (string) $v['verification'], 'confirmed' => $v['relationship_confirmed_at'] !== null,
                'relationship_source' => $v['relationship_source'] === null ? null : (string) $v['relationship_source'],
                'version' => (int) $v['row_version'], 'values' => $this->values('variant_id', (int) $v['id']),
            ];
        }

        $description = [];
        foreach (['fa', 'en'] as $lang) {
            $json = $p["description_{$lang}"];
            $doc = \is_string($json) ? json_decode($json, true) : null;
            $description[$lang] = ['text' => \is_array($doc) ? BlockSyntax::format($doc) : '', 'editable' => !\is_array($doc) || BlockSyntax::format($doc) !== null];
        }
        $issues = (new PageVerification($this->pdo, $this->audit))->issues($id);
        $listed = array_values(array_filter($items, static fn(array $i): bool => $i['listed'] && ($i['kind'] === 'base' || $i['status'] === 'published')));
        $verified = $p['verification'] === 'verified';

        return [
            'id' => $id, 'designation' => (string) $p['designation'], 'slug' => (string) $p['slug'], 'family_id' => (int) $p['family_id'], 'family' => (string) $p['family_fa'],
            'status' => (string) $p['status'], 'verification' => (string) $p['verification'], 'verified' => $verified, 'base_listed' => (int) $p['base_listed'] === 1,
            'explorer' => (int) $p['show_in_explorer'] === 1, 'sample' => (int) $p['is_sample'] === 1, 'review' => (int) $p['relationship_review'] === 1,
            'review_note' => $p['review_note'] === null ? null : (string) $p['review_note'], 'published_once' => $p['first_published_at'] !== null,
            'version' => (int) $p['row_version'], 'updated_at' => (string) $p['updated_at'],
            'keys' => $keys, 'items' => $items, 'spellings' => $this->spellings($id),
            'sources' => array_map(static fn(array $s): array => ['id' => (int) $s['id'], 'title' => (string) $s['title'], 'url' => (string) ($s['url'] ?? '')], Sql::rows($this->pdo, 'SELECT id, title, url FROM source ORDER BY title, id')),
            'description' => $description, 'issues' => $issues,
            // What the site will do with this page once published (A3 note 7).
            'indexable' => SiteBuilder::indexable(['verified' => $verified, 'items' => $listed, 'is_sample' => (int) $p['is_sample'] === 1]) && $p['status'] === 'published',
            'last_editor' => $this->lastEditor($id),
        ];
    }

    public function create(Actor $actor, Request $r): int
    {
        return $this->products->createProduct($actor, (int) $r->input('family_id'), $r->input('designation'));
    }

    /** Identity and publication: one transaction, so the page's version moves once per save. */
    public function save(Actor $actor, int $id, Request $r): void
    {
        Transaction::run($this->pdo, function () use ($actor, $id, $r): void {
            $version = (int) $r->input('version');
            $current = $this->products->product($id);
            if ((int) $current['row_version'] !== $version) {
                throw CatalogueError::stale('Product');
            }
            $designation = trim($r->input('designation'));
            $baseListed = $r->input('base_listed') === '1';
            $explorer = $r->input('show_in_explorer') === '1';
            if ($designation !== $current['designation'] || $baseListed !== ((int) $current['base_listed'] === 1) || $explorer !== ((int) $current['show_in_explorer'] === 1)) {
                $this->products->updateProduct($actor, $id, $version, new ProductChanges(
                    designation: $designation !== $current['designation'] ? $designation : null,
                    baseListed: $baseListed,
                    showInExplorer: $explorer,
                ));
                $version = (int) $this->products->product($id)['row_version'];
            }
            $status = $r->input('status');
            if ($status !== '' && $status !== $current['status']) {
                $this->products->setProductStatus($actor, $id, $version, $status);
            }
        });
    }

    public function setValue(Actor $actor, int $productId, Request $r): void
    {
        $ref = $this->ref($productId, $r->input('item'));
        $version = $r->input('version');
        (new SpecValueService($this->pdo, $this->audit))->setValue($actor, $ref, (int) $r->input('key'), new ValueInput(
            valueText: $r->input('value'),
            status: $r->input('status') === 'verified' ? 'verified' : 'pending',
            sourceId: $r->input('source') === '' ? null : (int) $r->input('source'),
            checkedOn: $r->input('checked_on') === '' ? null : $r->input('checked_on'),
            sourceLocator: trim($r->input('locator')) === '' ? null : trim($r->input('locator')),
        ), $version === '' ? null : (int) $version);
    }

    public function removeValue(Actor $actor, int $productId, int $valueId, Request $r): void
    {
        $owner = Sql::scalar($this->pdo, 'SELECT COALESCE(v.product_id, pv.product_id) FROM spec_value v LEFT JOIN product_variant pv ON pv.id = v.variant_id WHERE v.id = ?', [$valueId]);
        if ($owner === null || (int) $owner !== $productId) {
            throw CatalogueError::notFound('value');
        }
        (new SpecValueService($this->pdo, $this->audit))->removeValue($actor, $valueId, (int) $r->input('version'));
    }

    public function addVariant(Actor $actor, int $productId, Request $r): void
    {
        if ($r->input('source') === '') {
            throw CatalogueError::invalid('A variant relationship needs a cited source.');
        }
        $this->products->addVariant($actor, $productId, $r->input('designation'), (int) $r->input('source'), $r->input('listed') === '1');
    }

    public function updateVariant(Actor $actor, int $productId, int $variantId, Request $r): void
    {
        $this->assertVariantOf($productId, $variantId);
        $status = $r->input('status');
        $this->products->updateVariant($actor, $variantId, (int) $r->input('version'), new VariantChanges(listed: $r->input('listed') === '1', status: $status === '' ? null : $status));
    }

    public function addSpelling(Actor $actor, int $productId, Request $r): void
    {
        $target = $r->input('target');
        $variant = str_starts_with($target, 'v') ? (int) substr($target, 1) : null;
        if ($variant !== null) {
            $this->assertVariantOf($productId, $variant);
        }
        $this->products->addSpelling($actor, $variant === null ? $productId : null, $variant, $r->input('spelling'), 'writing', $r->input('source') === '' ? null : (int) $r->input('source'));
    }

    public function removeSpelling(Actor $actor, int $productId, int $spellingId): void
    {
        $owner = Sql::scalar($this->pdo, 'SELECT COALESCE(s.product_id, v.product_id) FROM spelling s LEFT JOIN product_variant v ON v.id = s.variant_id WHERE s.id = ?', [$spellingId]);
        if ($owner === null || (int) $owner !== $productId) {
            throw CatalogueError::notFound('spelling');
        }
        $this->products->removeSpelling($actor, $spellingId);
    }

    public function saveDescription(Actor $actor, int $productId, Request $r): void
    {
        $lang = $r->input('lang') === 'en' ? 'en' : 'fa';
        try {
            $doc = BlockSyntax::parse($r->input('text'));
        } catch (Throwable $e) {
            throw CatalogueError::invalid('The description text could not be read: ' . $e->getMessage());
        }
        (new ContentService($this->pdo, $this->audit))->setProductDescription($actor, $productId, (int) $r->input('version'), $lang, $doc);
    }

    public function verify(Actor $actor, int $productId, Request $r): void
    {
        (new PageVerification($this->pdo, $this->audit))->verify($actor, $productId, (int) $r->input('version'));
    }

    public function confirmStandalone(Actor $actor, int $productId, Request $r): void
    {
        (new RelationshipReview($this->pdo, $this->audit))->confirmStandalone($actor, $productId, trim($r->input('note')));
    }

    public function addSource(Actor $actor, Request $r): int
    {
        return (new SourceService($this->pdo, $this->audit))->create($actor, $r->input('title'), $r->input('url'), $r->input('publisher'), $r->input('edition'));
    }

    // ---- helpers

    private function ref(int $productId, string $item): DesignationRef
    {
        if ($item === 'base') {
            return DesignationRef::base($productId);
        }
        $variant = str_starts_with($item, 'v') ? (int) substr($item, 1) : 0;
        $this->assertVariantOf($productId, $variant);

        return DesignationRef::variant($variant);
    }

    private function assertVariantOf(int $productId, int $variantId): void
    {
        if (Sql::int($this->pdo, 'SELECT COUNT(*) FROM product_variant WHERE id = ? AND product_id = ?', [$variantId, $productId]) === 0) {
            throw CatalogueError::notFound('variant');
        }
    }

    /** @return array<int, array<string, mixed>> spec key id => value row */
    private function values(string $column, int $id): array
    {
        $out = [];
        foreach (Sql::rows($this->pdo, "SELECT v.*, s.title AS source_title FROM spec_value v LEFT JOIN source s ON s.id = v.source_id WHERE v.{$column} = ?", [$id]) as $v) {
            $out[(int) $v['spec_key_id']] = [
                'id' => (int) $v['id'], 'value' => (string) $v['value_text'], 'unit' => $v['unit'] === null ? '' : (string) $v['unit'], 'status' => (string) $v['status'],
                'source' => $v['source_id'] === null ? null : (int) $v['source_id'], 'source_title' => $v['source_title'] === null ? null : (string) $v['source_title'],
                'locator' => $v['source_locator'] === null ? '' : (string) $v['source_locator'], 'checked_on' => $v['checked_on'] === null ? '' : (string) $v['checked_on'],
                'version' => (int) $v['row_version'],
            ];
        }

        return $out;
    }

    /** @return list<array{id: int, spelling: string, target: string, equivalence: string}> */
    private function spellings(int $productId): array
    {
        return array_map(static fn(array $s): array => ['id' => (int) $s['id'], 'spelling' => (string) $s['spelling'], 'target' => (string) $s['target'], 'equivalence' => (string) $s['equivalence']], Sql::rows(
            $this->pdo,
            'SELECT s.id, s.spelling, s.equivalence, COALESCE(p.designation, v.designation) AS target FROM spelling s LEFT JOIN product p ON p.id = s.product_id LEFT JOIN product_variant v ON v.id = s.variant_id WHERE s.product_id = ? OR v.product_id = ? ORDER BY s.id',
            [$productId, $productId],
        ));
    }

    /**
     * Core values with a cited source, out of the core values the listed items need (A2 "منبع").
     *
     * @param list<int> $coreKeys
     *
     * @return array{int, int}
     */
    private function sourceCoverage(int $productId, array $coreKeys, bool $baseListed): array
    {
        if ($coreKeys === []) {
            return [0, 0];
        }
        $items = $baseListed ? [['product_id', $productId]] : [];
        foreach (Sql::column($this->pdo, "SELECT id FROM product_variant WHERE product_id = ? AND listed = 1 AND status <> 'archived'", [$productId]) as $v) {
            $items[] = ['variant_id', (int) $v];
        }
        $sourced = 0;
        $marks = implode(',', array_fill(0, \count($coreKeys), '?'));
        foreach ($items as [$column, $id]) {
            $sourced += Sql::int($this->pdo, "SELECT COUNT(*) FROM spec_value WHERE {$column} = ? AND source_id IS NOT NULL AND spec_key_id IN ({$marks})", [$id, ...$coreKeys]);
        }

        return [$sourced, \count($items) * \count($coreKeys)];
    }

    /** @return array{name: string, at: string}|null */
    private function lastEditor(int $productId): ?array
    {
        $row = Sql::row($this->pdo, "SELECT u.display_name, a.occurred_at FROM audit_event a JOIN app_user u ON u.id = a.user_id WHERE a.entity_type = 'product' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 1", [$productId]);

        return $row === null ? null : ['name' => (string) $row['display_name'], 'at' => (string) $row['occurred_at']];
    }

    private static function key(string $search): string
    {
        try {
            return Normaliser::key($search);
        } catch (Throwable) {
            return '';
        }
    }
}
