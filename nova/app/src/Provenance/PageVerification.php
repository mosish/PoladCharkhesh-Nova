<?php

declare(strict_types=1);

namespace Nova\Provenance;

use Nova\Audit\AuditLog;
use Nova\Catalogue\CatalogueError;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Security\Actor;
use PDO;

/**
 * Page verification for indexing: Owner only (decision C, 05 §11). A Product
 * page is verifiable when
 * - its designation is confirmed (no open relationship review),
 * - its family has core spec keys (V-6 leaves some families undefined, and
 *   then nothing can be verified rather than verified vacuously),
 * - it has at least one listed item, each listed variant's relationship is
 *   confirmed, and
 * - every listed item has every core value, sourced and verified.
 */
final readonly class PageVerification
{
    public function __construct(private PDO $pdo, private AuditLog $audit) {}

    /** @return list<string> unmet requirements; empty when the page can be verified */
    public function issues(int $productId): array
    {
        $product = Sql::row($this->pdo, 'SELECT * FROM product WHERE id = ?', [$productId]) ?? throw CatalogueError::notFound('Product');
        $issues = [];
        if ((int) $product['relationship_review'] === 1) {
            $issues[] = 'relationship_review: the designation\'s relationship is still under review';
        }
        $coreKeys = Sql::pairs($this->pdo, 'SELECT id, code FROM spec_key WHERE family_id = ? AND is_core = 1 ORDER BY sort, id', [(int) $product['family_id']]);
        if ($coreKeys === []) {
            $issues[] = 'no_core_keys: the family has no core spec keys defined (V-6)';
        }

        $items = [];
        if ((int) $product['base_listed'] === 1) {
            $items[] = ['product_id', $productId, (string) $product['designation'], true];
        }
        foreach (Sql::rows($this->pdo, "SELECT id, designation, relationship_confirmed_at FROM product_variant WHERE product_id = ? AND listed = 1 AND status <> 'archived' ORDER BY sort, id", [$productId]) as $v) {
            $items[] = ['variant_id', (int) $v['id'], (string) $v['designation'], $v['relationship_confirmed_at'] !== null];
        }
        if ($items === []) {
            $issues[] = 'no_listed_items: nothing on the page is listed';
        }

        foreach ($items as [$column, $id, $designation, $confirmed]) {
            if (!$confirmed) {
                $issues[] = "unconfirmed_variant: {$designation}";
            }
            $values = Sql::pairs($this->pdo, "SELECT spec_key_id, status FROM spec_value WHERE {$column} = ?", [$id]);
            foreach ($coreKeys as $keyId => $code) {
                $status = $values[$keyId] ?? null;
                if ($status === null) {
                    $issues[] = "missing_value: {$designation} {$code}";
                } elseif ($status !== 'verified') {
                    $issues[] = "unverified_value: {$designation} {$code}";
                }
            }
        }

        return $issues;
    }

    public function verify(Actor $actor, int $productId, int $expectedVersion): void
    {
        if (!$actor->isOwner()) {
            throw CatalogueError::forbidden('verify a page for indexing');
        }
        Transaction::run($this->pdo, function () use ($actor, $productId, $expectedVersion): void {
            // Lock first, read second: the lock waits for any in-flight edit of this page, and the
            // snapshot used by issues() is only taken after it, so it sees that edit (no fail-open race).
            if (Sql::row($this->pdo, 'SELECT id FROM product WHERE id = ? FOR UPDATE', [$productId]) === null) {
                throw CatalogueError::notFound('Product');
            }
            $issues = $this->issues($productId);
            if ($issues !== []) {
                throw CatalogueError::conflict('The page cannot be verified yet: ' . implode('; ', $issues));
            }
            $updated = Sql::run($this->pdo, "UPDATE product SET verification = 'verified', verified_at = UTC_TIMESTAMP(), verified_by = ?, row_version = row_version + 1 WHERE id = ? AND row_version = ?", [$actor->userId, $productId, $expectedVersion]);
            if ($updated->rowCount() === 0) {
                throw CatalogueError::stale('Product');
            }
            Sql::run($this->pdo, "UPDATE product_variant SET verification = 'verified', verified_at = UTC_TIMESTAMP(), verified_by = ? WHERE product_id = ? AND listed = 1 AND status <> 'archived'", [$actor->userId, $productId]);
            $this->audit->record($actor, 'product.verify', 'product', $productId, null, ['verification' => 'pending'], ['verification' => 'verified']);
        });
    }
}
