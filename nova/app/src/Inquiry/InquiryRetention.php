<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Audit\AuditLog;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Security\Actor;
use PDO;

/**
 * Retention (E-12, the published privacy text): an inquiry and its items are
 * deleted 24 months after they were received, and on request. Runs from
 * `app/bin/nova inquiry:purge` and opportunistically after each stored
 * inquiry; the scheduler of step 12 will also run it. The audit trail keeps
 * only the reference and count, never the deleted content.
 */
final readonly class InquiryRetention
{
    public const int MONTHS = 24;

    public function __construct(private PDO $pdo) {}

    /** Deletes inquiries past retention, in batches. @return int inquiries deleted */
    public function purgeExpired(int $batch = 200): int
    {
        $ids = array_map(intval(...), Sql::column($this->pdo, 'SELECT id FROM inquiry WHERE received_at < UTC_TIMESTAMP(6) - INTERVAL ' . self::MONTHS . " MONTH ORDER BY id LIMIT {$batch}"));
        if ($ids === []) {
            return 0;
        }
        Transaction::run($this->pdo, function () use ($ids): void {
            $marks = implode(',', array_fill(0, \count($ids), '?'));
            Sql::run($this->pdo, "DELETE FROM inquiry_item WHERE inquiry_id IN ({$marks})", $ids);
            Sql::run($this->pdo, "DELETE FROM inquiry WHERE id IN ({$marks})", $ids);
            (new AuditLog($this->pdo))->record(null, 'inquiry.purged', 'inquiry', null, (string) \count($ids), null, ['count' => \count($ids), 'retention_months' => self::MONTHS]);
        });

        return \count($ids);
    }

    /** Deletion on request (privacy text). Owner only. */
    public function delete(Actor $actor, int $id): bool
    {
        if (!$actor->isOwner()) {
            throw \Nova\Catalogue\CatalogueError::forbidden('Only the Owner deletes an inquiry.');
        }

        return Transaction::run($this->pdo, function () use ($actor, $id): bool {
            if (Sql::scalar($this->pdo, 'SELECT id FROM inquiry WHERE id = ? FOR UPDATE', [$id]) === null) {
                return false;
            }
            Sql::run($this->pdo, 'DELETE FROM inquiry_item WHERE inquiry_id = ?', [$id]);
            Sql::run($this->pdo, 'DELETE FROM inquiry WHERE id = ?', [$id]);
            (new AuditLog($this->pdo))->record($actor, 'inquiry.deleted', 'inquiry', $id, InquiryStore::reference($id));

            return true;
        });
    }
}
