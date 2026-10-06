<?php

declare(strict_types=1);

namespace Nova\Provenance;

use Nova\Audit\AuditLog;
use Nova\Core\Sql;
use Nova\Security\Actor;
use PDO;

/**
 * E-9: editing a core value, its source or the listed items returns the page
 * (and the affected listed items) to pending until the Owner verifies again.
 */
final class VerificationReset
{
    public static function product(PDO $pdo, AuditLog $audit, ?Actor $actor, int $productId, string $why, ?int $variantId = null): void
    {
        $was = Sql::scalar($pdo, 'SELECT verification FROM product WHERE id = ?', [$productId]);
        if ($was === 'verified') {
            Sql::run($pdo, "UPDATE product SET verification = 'pending', verified_at = NULL, verified_by = NULL, row_version = row_version + 1 WHERE id = ?", [$productId]);
            $audit->record($actor, 'product.verification_reset', 'product', $productId, null, ['verification' => 'verified'], ['verification' => 'pending', 'reason' => $why]);
        }
        if ($variantId !== null) {
            Sql::run($pdo, "UPDATE product_variant SET verification = 'pending', verified_at = NULL, verified_by = NULL WHERE id = ? AND verification = 'verified'", [$variantId]);
        }
    }
}
