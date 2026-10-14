<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Audit\AuditLog;
use Nova\Core\Sql;
use PDO;
use Throwable;

/**
 * Writes an inquiry, its items and their match context in one transaction
 * (05 §13), before any notification is attempted. The audit event records
 * only the reference, language and match types: never the visitor's name,
 * phone or message, because the audit trail outlives the 24-month retention
 * (E-12).
 */
final readonly class InquiryStore
{
    public function __construct(private PDO $pdo) {}

    /**
     * @param array{name: string, phone: string, code: string, qty: string, msg: string} $values
     * @param list<InquiryItem> $items
     * @param 'matched'|'no_code'|'no_release'|'unavailable' $matchState
     */
    public function store(string $lang, array $values, string $matchState, ?string $release, array $items): int
    {
        $this->pdo->beginTransaction();
        try {
            Sql::run(
                $this->pdo,
                'INSERT INTO inquiry (lang, name, phone, quantity, message, release_id, match_state) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$lang, $values['name'], $values['phone'], $values['qty'] === '' ? null : $values['qty'], $values['msg'] === '' ? null : $values['msg'], $release, $matchState],
            );
            $id = (int) $this->pdo->lastInsertId();
            foreach ($items as $position => $item) {
                Sql::run(
                    $this->pdo,
                    'INSERT INTO inquiry_item (inquiry_id, position, requested_text, requested_key, match_type, via_spelling, spelling, no_match_reason, product_ref, product_designation, variant_ref, variant_designation, chosen_ref, chosen_designation, listed_text, context_differs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $id, $position + 1, $item->requested, $item->key, $item->type?->value, $item->spelling === null ? 0 : 1, $item->spelling, $item->reason,
                        $item->productRef, $item->productDesignation, $item->variantRef, $item->variantDesignation, $item->chosenRef, $item->chosenDesignation,
                        $item->listed === [] ? null : json_encode($item->listed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $item->contextDiffers ? 1 : 0,
                    ],
                );
            }
            (new AuditLog($this->pdo))->record(null, 'inquiry.received', 'inquiry', $id, self::reference($id), null, [
                'lang' => $lang, 'release' => $release, 'match_state' => $matchState,
                'match' => array_map(static fn(InquiryItem $i): ?string => $i->type?->value, $items),
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $id;
    }

    public static function reference(int $id): string
    {
        return 'IQ-' . $id;
    }
}
