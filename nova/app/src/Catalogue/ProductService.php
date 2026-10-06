<?php

declare(strict_types=1);

namespace Nova\Catalogue;

use Nova\Audit\AuditLog;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Provenance\VerificationReset;
use Nova\Security\Actor;
use PDO;
use PDOException;

/**
 * Products, variants and spellings (05 §9, D20/E-2).
 *
 * - The Product is the stable designation entity; base_listed alone says the
 *   plain designation is listed. Nothing here ever makes a variant stand for
 *   its Product.
 * - A variant or spelling is a relationship claim: only the Owner records one,
 *   a variant always with a cited source (an unresolved designation stays a
 *   standalone Product flagged for review; see RelationshipReview).
 * - Every change: one transaction, optimistic lock (row_version), audit event.
 */
final readonly class ProductService
{
    public const array STATUSES = ['draft', 'published', 'archived'];

    private DesignationKeys $keys;

    public function __construct(private PDO $pdo, private AuditLog $audit)
    {
        $this->keys = new DesignationKeys($pdo);
    }

    // ---- Products

    public function createProduct(Actor $actor, int $familyId, string $designation, ?string $slug = null, bool $relationshipReview = false, ?string $reviewNote = null): int
    {
        $designation = DesignationKeys::clean($designation);
        $slug ??= Slug::fromDesignation($designation);
        Slug::assertValid($slug);
        $this->assertFamily($familyId);

        return Transaction::run($this->pdo, function () use ($actor, $familyId, $designation, $slug, $relationshipReview, $reviewNote): int {
            $this->assertSlugFree($slug);
            Sql::run($this->pdo, 'INSERT INTO product (family_id, designation, slug, relationship_review, review_note) VALUES (?, ?, ?, ?, ?)', [$familyId, $designation, $slug, $relationshipReview ? 1 : 0, $reviewNote]);
            $id = (int) $this->pdo->lastInsertId();
            $this->keys->claim($designation, 'product', $id);
            $this->audit->record($actor, 'product.create', 'product', $id, $designation, null, ['designation' => $designation, 'slug' => $slug, 'family_id' => $familyId, 'relationship_review' => $relationshipReview]);

            return $id;
        });
    }

    public function updateProduct(Actor $actor, int $id, int $expectedVersion, ProductChanges $changes): void
    {
        Transaction::run($this->pdo, function () use ($actor, $id, $expectedVersion, $changes): void {
            $before = $this->product($id);
            $after = $before;

            if ($changes->designation !== null) {
                $after['designation'] = DesignationKeys::clean($changes->designation);
            }
            if ($changes->slug !== null && $changes->slug !== $before['slug']) {
                if ($before['first_published_at'] !== null) {
                    throw CatalogueError::conflict('The slug is part of a published URL and can no longer change.');
                }
                Slug::assertValid($changes->slug);
                $this->assertSlugFree($changes->slug);
                $after['slug'] = $changes->slug;
            }
            if ($changes->familyId !== null && $changes->familyId !== (int) $before['family_id']) {
                $this->assertFamily($changes->familyId);
                if (Sql::int($this->pdo, 'SELECT COUNT(*) FROM spec_value v LEFT JOIN product_variant pv ON pv.id = v.variant_id WHERE v.product_id = ? OR pv.product_id = ?', [$id, $id]) > 0) {
                    throw CatalogueError::conflict('The family cannot change while values exist for this Product; its spec keys belong to the current family.');
                }
                $after['family_id'] = $changes->familyId;
            }
            if ($changes->baseListed !== null) {
                $after['base_listed'] = $changes->baseListed ? 1 : 0;
            }
            if ($changes->showInExplorer !== null) {
                $after['show_in_explorer'] = $changes->showInExplorer ? 1 : 0;
            }

            $this->bump('product', $id, $expectedVersion, [
                'designation' => $after['designation'], 'slug' => $after['slug'], 'family_id' => $after['family_id'],
                'base_listed' => $after['base_listed'], 'show_in_explorer' => $after['show_in_explorer'],
            ]);
            if ($after['designation'] !== $before['designation']) {
                $this->keys->rekey((string) $after['designation'], 'product', $id);
            }
            if ($after['designation'] !== $before['designation'] || $after['family_id'] !== $before['family_id'] || $after['base_listed'] !== $before['base_listed']) {
                VerificationReset::product($this->pdo, $this->audit, $actor, $id, 'the designation, family or listed items changed');
            }
            $this->audit->record($actor, 'product.update', 'product', $id, (string) $after['designation'], self::pick($before), self::pick($after));
        });
    }

    /** draft → published (included in the next publish) → archived (removed; its URL answers 410 once it was public). */
    public function setProductStatus(Actor $actor, int $id, int $expectedVersion, string $status): void
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw CatalogueError::invalid("Unknown status \"{$status}\".");
        }
        Transaction::run($this->pdo, function () use ($actor, $id, $expectedVersion, $status): void {
            $before = $this->product($id);
            $this->bump('product', $id, $expectedVersion, ['status' => $status]);
            $path = '/p/' . $before['slug'] . '/';
            if ($status === 'archived' && $before['first_published_at'] !== null) {
                Sql::run($this->pdo, 'INSERT IGNORE INTO redirect (from_path, status, reason, created_by) VALUES (?, 410, ?, ?)', [$path, 'Product archived', $actor->userId]);
            }
            if ($status !== 'archived') {
                Sql::run($this->pdo, "DELETE FROM redirect WHERE from_path = ? AND status = 410 AND reason = 'Product archived'", [$path]);
            }
            $this->audit->record($actor, 'product.status', 'product', $id, (string) $before['designation'], ['status' => $before['status']], ['status' => $status]);
        });
    }

    /** Only a Product that was never published can be deleted; published ones are archived (E-13). */
    public function deleteDraftProduct(Actor $actor, int $id): void
    {
        Transaction::run($this->pdo, function () use ($actor, $id): void {
            $before = $this->product($id);
            if ($before['first_published_at'] !== null) {
                throw CatalogueError::conflict('A Product that has been published is archived, never deleted.');
            }
            Sql::run($this->pdo, 'DELETE FROM product WHERE id = ?', [$id]);
            $this->audit->record($actor, 'product.delete', 'product', $id, (string) $before['designation'], self::pick($before), null);
        });
    }

    // ---- Variants

    /**
     * Records a verified relationship: `$designation` is a distinct variant of
     * the Product (05 §9). Owner only, with a cited source.
     */
    public function addVariant(Actor $actor, int $productId, string $designation, int $sourceId, bool $listed = false, ?string $slug = null): int
    {
        if (!$actor->isOwner()) {
            throw CatalogueError::forbidden('record a variant relationship');
        }
        $designation = DesignationKeys::clean($designation);
        $slug ??= Slug::fromDesignation($designation);
        Slug::assertValid($slug);

        return Transaction::run($this->pdo, function () use ($actor, $productId, $designation, $sourceId, $listed, $slug): int {
            $product = $this->product($productId);
            $this->assertSource($sourceId);
            $this->assertSlugFree($slug);
            $sort = Sql::int($this->pdo, 'SELECT COALESCE(MAX(sort) + 1, 0) FROM product_variant WHERE product_id = ?', [$productId]);
            Sql::run($this->pdo, 'INSERT INTO product_variant (product_id, designation, slug, listed, sort, relationship_source_id, relationship_confirmed_by, relationship_confirmed_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$productId, $designation, $slug, $listed ? 1 : 0, $sort, $sourceId, $actor->userId]);
            $id = (int) $this->pdo->lastInsertId();
            $this->keys->claim($designation, 'variant', $id);
            if ($listed) {
                VerificationReset::product($this->pdo, $this->audit, $actor, $productId, 'a listed item was added');
            }
            $this->audit->record($actor, 'variant.create', 'product_variant', $id, $designation, null, ['product' => $product['designation'], 'designation' => $designation, 'slug' => $slug, 'listed' => $listed, 'relationship_source_id' => $sourceId]);

            return $id;
        });
    }

    public function updateVariant(Actor $actor, int $id, int $expectedVersion, VariantChanges $changes): void
    {
        if ($changes->status !== null && !\in_array($changes->status, self::STATUSES, true)) {
            throw CatalogueError::invalid("Unknown status \"{$changes->status}\".");
        }
        Transaction::run($this->pdo, function () use ($actor, $id, $expectedVersion, $changes): void {
            $before = $this->variant($id);
            $after = [
                'listed' => $changes->listed === null ? $before['listed'] : ($changes->listed ? 1 : 0),
                'status' => $changes->status ?? $before['status'],
                'notes' => $changes->notes ?? $before['notes'],
                'sort' => $changes->sort ?? $before['sort'],
            ];
            $this->bump('product_variant', $id, $expectedVersion, $after);
            if ($after['listed'] !== $before['listed'] || $after['status'] !== $before['status']) {
                VerificationReset::product($this->pdo, $this->audit, $actor, (int) $before['product_id'], 'the listed items changed');
            }
            $this->audit->record($actor, 'variant.update', 'product_variant', $id, (string) $before['designation'], array_intersect_key($before, $after), $after);
        });
    }

    // ---- Spellings

    /** Owner-recorded alternative writing of exactly one Product or variant; a brand equivalent needs a source (05 §9, V-4). */
    public function addSpelling(Actor $actor, ?int $productId, ?int $variantId, string $spelling, string $equivalence = 'writing', ?int $sourceId = null, ?string $note = null): int
    {
        if (!$actor->isOwner()) {
            throw CatalogueError::forbidden('record a spelling');
        }
        if (($productId === null) === ($variantId === null)) {
            throw CatalogueError::invalid('A spelling targets exactly one Product or variant.');
        }
        if (!\in_array($equivalence, ['writing', 'brand'], true)) {
            throw CatalogueError::invalid("Unknown equivalence \"{$equivalence}\".");
        }
        if ($equivalence === 'brand' && $sourceId === null) {
            throw CatalogueError::invalid('A brand equivalent needs a cited source.');
        }
        $spelling = DesignationKeys::clean($spelling);

        return Transaction::run($this->pdo, function () use ($actor, $productId, $variantId, $spelling, $equivalence, $sourceId, $note): int {
            $target = $productId !== null ? $this->product($productId)['designation'] : $this->variant((int) $variantId)['designation'];
            if ($sourceId !== null) {
                $this->assertSource($sourceId);
            }
            Sql::run($this->pdo, 'INSERT INTO spelling (product_id, variant_id, spelling, equivalence, source_id, note, confirmed_by, confirmed_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [$productId, $variantId, $spelling, $equivalence, $sourceId, $note, $actor->userId]);
            $id = (int) $this->pdo->lastInsertId();
            $this->keys->claim($spelling, 'spelling', $id);
            $this->audit->record($actor, 'spelling.create', 'spelling', $id, $spelling, null, ['target' => $target, 'spelling' => $spelling, 'equivalence' => $equivalence, 'source_id' => $sourceId]);

            return $id;
        });
    }

    public function removeSpelling(Actor $actor, int $id): void
    {
        if (!$actor->isOwner()) {
            throw CatalogueError::forbidden('remove a spelling');
        }
        Transaction::run($this->pdo, function () use ($actor, $id): void {
            $row = Sql::row($this->pdo, 'SELECT spelling FROM spelling WHERE id = ?', [$id]) ?? throw CatalogueError::notFound('spelling');
            Sql::run($this->pdo, 'DELETE FROM spelling WHERE id = ?', [$id]);
            $this->audit->record($actor, 'spelling.delete', 'spelling', $id, (string) $row['spelling'], $row, null);
        });
    }

    // ---- helpers

    /** @return array<string, scalar|null> */
    public function product(int $id): array
    {
        return Sql::row($this->pdo, 'SELECT * FROM product WHERE id = ? FOR UPDATE', [$id]) ?? throw CatalogueError::notFound('Product');
    }

    /** @return array<string, scalar|null> */
    public function variant(int $id): array
    {
        return Sql::row($this->pdo, 'SELECT * FROM product_variant WHERE id = ? FOR UPDATE', [$id]) ?? throw CatalogueError::notFound('variant');
    }

    /** @param array<string, scalar|null> $columns */
    private function bump(string $table, int $id, int $expectedVersion, array $columns): void
    {
        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($columns)));
        try {
            $statement = Sql::run($this->pdo, "UPDATE {$table} SET {$set}, row_version = row_version + 1 WHERE id = ? AND row_version = ?", [...array_values($columns), $id, $expectedVersion]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), '_slug')) {
                throw CatalogueError::conflict('That slug is already used.');
            }

            throw $e;
        }
        if ($statement->rowCount() === 0 && Sql::int($this->pdo, "SELECT row_version FROM {$table} WHERE id = ?", [$id]) !== $expectedVersion) {
            throw CatalogueError::stale($table === 'product' ? 'Product' : 'variant');
        }
    }

    private function assertFamily(int $familyId): void
    {
        if (Sql::int($this->pdo, 'SELECT COUNT(*) FROM family WHERE id = ?', [$familyId]) === 0) {
            throw CatalogueError::notFound('family');
        }
    }

    private function assertSource(int $sourceId): void
    {
        if (Sql::int($this->pdo, 'SELECT COUNT(*) FROM source WHERE id = ?', [$sourceId]) === 0) {
            throw CatalogueError::notFound('source');
        }
    }

    private function assertSlugFree(string $slug): void
    {
        if (Sql::int($this->pdo, 'SELECT (SELECT COUNT(*) FROM product WHERE slug = ?) + (SELECT COUNT(*) FROM product_variant WHERE slug = ?)', [$slug, $slug]) > 0) {
            throw CatalogueError::conflict("The slug \"{$slug}\" is already used.");
        }
    }

    /**
     * @param array<string, scalar|null> $row
     *
     * @return array<string, scalar|null>
     */
    private static function pick(array $row): array
    {
        return array_intersect_key($row, array_flip(['designation', 'slug', 'family_id', 'base_listed', 'show_in_explorer', 'status']));
    }
}
