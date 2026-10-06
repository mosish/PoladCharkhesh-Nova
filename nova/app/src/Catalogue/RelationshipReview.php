<?php

declare(strict_types=1);

namespace Nova\Catalogue;

use Nova\Audit\AuditLog;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Provenance\VerificationReset;
use Nova\Security\Actor;
use PDO;

/**
 * Designations whose base/suffix relationship is not verified stay standalone
 * Products flagged for review (05 §9). The Owner settles each one:
 * - confirmStandalone(): it really is its own designation;
 * - resolveAsVariant(): with a cited source, it becomes a variant of another
 *   Product. Its key, values, documents and spellings move with it (they
 *   belong to that exact designation, so nothing is copied), and its old
 *   public URL answers 301 to the Product page anchor.
 */
final readonly class RelationshipReview
{
    public function __construct(private PDO $pdo, private AuditLog $audit) {}

    public function confirmStandalone(Actor $actor, int $productId, string $note): void
    {
        if (!$actor->isOwner()) {
            throw CatalogueError::forbidden('settle a relationship review');
        }
        Transaction::run($this->pdo, function () use ($actor, $productId, $note): void {
            $product = Sql::row($this->pdo, 'SELECT designation, relationship_review FROM product WHERE id = ? FOR UPDATE', [$productId]) ?? throw CatalogueError::notFound('Product');
            Sql::run($this->pdo, 'UPDATE product SET relationship_review = 0, review_note = ?, row_version = row_version + 1 WHERE id = ?', [$note, $productId]);
            $this->audit->record($actor, 'relationship.standalone', 'product', $productId, (string) $product['designation'], ['relationship_review' => (int) $product['relationship_review']], ['relationship_review' => 0, 'note' => $note]);
        });
    }

    /** @return int the new variant's id */
    public function resolveAsVariant(Actor $actor, int $standaloneId, int $targetProductId, int $sourceId, string $note): int
    {
        if (!$actor->isOwner()) {
            throw CatalogueError::forbidden('resolve a relationship');
        }
        if ($standaloneId === $targetProductId) {
            throw CatalogueError::invalid('A Product cannot become a variant of itself.');
        }

        return Transaction::run($this->pdo, function () use ($actor, $standaloneId, $targetProductId, $sourceId, $note): int {
            $old = Sql::row($this->pdo, 'SELECT * FROM product WHERE id = ? FOR UPDATE', [$standaloneId]) ?? throw CatalogueError::notFound('Product');
            $target = Sql::row($this->pdo, 'SELECT * FROM product WHERE id = ? FOR UPDATE', [$targetProductId]) ?? throw CatalogueError::notFound('target Product');
            if (Sql::int($this->pdo, 'SELECT COUNT(*) FROM product_variant WHERE product_id = ?', [$standaloneId]) > 0) {
                throw CatalogueError::conflict('A Product that has its own variants cannot become a variant.');
            }
            if ($old['family_id'] !== $target['family_id']) {
                throw CatalogueError::conflict('The designation and the target Product belong to different families.');
            }
            if (Sql::int($this->pdo, 'SELECT COUNT(*) FROM source WHERE id = ?', [$sourceId]) === 0) {
                throw CatalogueError::notFound('source');
            }

            $sort = Sql::int($this->pdo, 'SELECT COALESCE(MAX(sort) + 1, 0) FROM product_variant WHERE product_id = ?', [$targetProductId]);
            $listed = (int) $old['base_listed'];
            // The variant keeps the designation's own slug, so its anchor is #v-<old slug>. The slug
            // must be free in the variant table first: release it from the Product row.
            Sql::run($this->pdo, 'UPDATE product SET slug = CONCAT(slug, \'-resolved-\', id) WHERE id = ?', [$standaloneId]);
            Sql::run($this->pdo, 'INSERT INTO product_variant (product_id, designation, slug, listed, status, sort, relationship_source_id, relationship_confirmed_by, relationship_confirmed_at, first_published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)', [$targetProductId, $old['designation'], $old['slug'], $listed, $old['status'] === 'archived' ? 'archived' : $old['status'], $sort, $sourceId, $actor->userId, $old['first_published_at']]);
            $variantId = (int) $this->pdo->lastInsertId();

            // Everything that belongs to this exact designation moves with it.
            Sql::run($this->pdo, 'UPDATE designation_key SET product_id = NULL, variant_id = ? WHERE product_id = ?', [$variantId, $standaloneId]);
            Sql::run($this->pdo, 'UPDATE spec_value SET product_id = NULL, variant_id = ? WHERE product_id = ?', [$variantId, $standaloneId]);
            Sql::run($this->pdo, 'UPDATE document_link SET product_id = NULL, variant_id = ? WHERE product_id = ?', [$variantId, $standaloneId]);
            Sql::run($this->pdo, 'UPDATE spelling SET product_id = NULL, variant_id = ? WHERE product_id = ?', [$variantId, $standaloneId]);
            Sql::run($this->pdo, 'DELETE FROM product WHERE id = ?', [$standaloneId]);

            if ($old['first_published_at'] !== null) {
                Sql::run($this->pdo, 'INSERT INTO redirect (from_path, to_path, status, reason, created_by) VALUES (?, ?, 301, ?, ?)', ['/p/' . $old['slug'] . '/', '/p/' . $target['slug'] . '/#v-' . $old['slug'], 'Designation resolved as a variant', $actor->userId]);
            }
            if ($listed === 1) {
                VerificationReset::product($this->pdo, $this->audit, $actor, $targetProductId, 'a listed item was added by a relationship resolution');
            }
            $this->audit->record($actor, 'relationship.resolve_variant', 'product_variant', $variantId, (string) $old['designation'], ['standalone_product' => $old['slug']], ['product' => $target['designation'], 'source_id' => $sourceId, 'note' => $note]);

            return $variantId;
        });
    }
}
