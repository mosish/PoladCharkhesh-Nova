<?php

declare(strict_types=1);

namespace Nova\Admin;

use Nova\Audit\AuditLog;
use Nova\Catalogue\CatalogueError;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Inquiry\InquiryStore;
use Nova\Security\Actor;
use PDO;

/**
 * What the Inquiries screen (frozen A6) and the dashboard (A1) show and
 * change: the inbox by status, one inquiry with its requested designations
 * as stored (never re-matched: the record says what the live site matched),
 * the status workflow and the internal note. Owner and Editor (Genesis §H);
 * deleting an inquiry and the notification address are Owner-only.
 * Audit events carry the reference and statuses, never visitor content.
 */
final readonly class InquiryAdmin
{
    public const array STATUSES = ['new', 'in_progress', 'answered', 'closed'];

    public function __construct(private PDO $pdo) {}

    /** @return array{new: int, in_progress: int, answered: int, closed: int, all: int, notify_failed: int, notify_pending: int, oldest_new: ?string} */
    public function counts(): array
    {
        $by = Sql::pairs($this->pdo, 'SELECT status, COUNT(*) FROM inquiry GROUP BY status');
        $counts = [];
        foreach (self::STATUSES as $s) {
            $counts[$s] = (int) ($by[$s] ?? 0);
        }
        $oldest = Sql::scalar($this->pdo, "SELECT MIN(received_at) FROM inquiry WHERE status = 'new'");

        return $counts + [
            'all' => array_sum($counts),
            'notify_failed' => Sql::int($this->pdo, "SELECT COUNT(*) FROM inquiry WHERE notify_status = 'failed'"),
            'notify_pending' => Sql::int($this->pdo, "SELECT COUNT(*) FROM inquiry WHERE notify_status IN ('pending', 'sending')"),
            'oldest_new' => $oldest === null ? null : (string) $oldest,
        ];
    }

    /** @return list<array<string, mixed>> newest first, each with its items */
    public function list(string $status, string $q, int $limit = 100): array
    {
        $where = [];
        $params = [];
        if (\in_array($status, self::STATUSES, true)) {
            $where[] = 'q.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(q.name LIKE ? OR q.phone LIKE ? OR EXISTS (SELECT 1 FROM inquiry_item i WHERE i.inquiry_id = q.id AND i.requested_text LIKE ?))';
            array_push($params, $like, $like, $like);
        }
        $rows = Sql::rows($this->pdo, 'SELECT q.id, q.received_at, q.lang, q.name, q.status, q.notify_status FROM inquiry q' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . " ORDER BY q.id DESC LIMIT {$limit}", $params);

        return array_map(fn(array $r): array => $r + ['ref' => InquiryStore::reference((int) $r['id']), 'items' => $this->items((int) $r['id'])], $rows);
    }

    /** @return array<string, mixed>|null */
    public function detail(int $id): ?array
    {
        $row = Sql::row($this->pdo, 'SELECT * FROM inquiry WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }

        return $row + ['ref' => InquiryStore::reference($id), 'items' => $this->items($id), 'wa' => self::waNumber((string) $row['phone']), 'tel' => self::telNumber((string) $row['phone'])];
    }

    public function setStatus(Actor $actor, int $id, string $status): void
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw CatalogueError::invalid("Unknown inquiry status {$status}.");
        }
        Transaction::run($this->pdo, function () use ($actor, $id, $status): void {
            $before = Sql::scalar($this->pdo, 'SELECT status FROM inquiry WHERE id = ? FOR UPDATE', [$id]) ?? throw CatalogueError::notFound('inquiry');
            if ($before === $status) {
                return;
            }
            Sql::run($this->pdo, 'UPDATE inquiry SET status = ?, status_changed_at = UTC_TIMESTAMP(6) WHERE id = ?', [$status, $id]);
            (new AuditLog($this->pdo))->record($actor, 'inquiry.status', 'inquiry', $id, InquiryStore::reference($id), ['status' => $before], ['status' => $status]);
        });
    }

    public function saveNote(Actor $actor, int $id, string $note): void
    {
        $note = trim($note);
        if (mb_strlen($note) > 2000) {
            throw CatalogueError::invalid('The note is longer than 2,000 characters.');
        }
        Transaction::run($this->pdo, function () use ($actor, $id, $note): void {
            if (Sql::scalar($this->pdo, 'SELECT id FROM inquiry WHERE id = ? FOR UPDATE', [$id]) === null) {
                throw CatalogueError::notFound('inquiry');
            }
            Sql::run($this->pdo, 'UPDATE inquiry SET note = ? WHERE id = ?', [$note === '' ? null : $note, $id]);
            // The note may hold personal details: the audit records only that it changed.
            (new AuditLog($this->pdo))->record($actor, 'inquiry.note', 'inquiry', $id, InquiryStore::reference($id));
        });
    }

    /**
     * Requested codes that matched nothing in the live release (A1 note 2,
     * C4): the demand signal, from what visitors wrote, never from tracking.
     *
     * @return list<array{code: string, count: int, last: string}>
     */
    public function notInCatalogue(int $limit = 8): array
    {
        return array_map(static fn(array $r): array => ['code' => (string) $r['code'], 'count' => (int) $r['n'], 'last' => (string) $r['last']], Sql::rows(
            $this->pdo,
            "SELECT MIN(i.requested_text) AS code, COUNT(*) AS n, MAX(q.received_at) AS last FROM inquiry_item i JOIN inquiry q ON q.id = i.inquiry_id WHERE i.match_type = 'none' GROUP BY i.requested_key ORDER BY n DESC, last DESC LIMIT {$limit}",
        ));
    }

    /** @return list<array<string, mixed>> */
    private function items(int $id): array
    {
        return array_map(static function (array $i): array {
            $listed = \is_string($i['listed_text'] ?? null) ? json_decode($i['listed_text'], true) : [];
            $i['listed'] = \is_array($listed) ? $listed : [];
            $i['product_slug'] = \is_string($i['product_ref'] ?? null) && str_starts_with($i['product_ref'], 'p:') ? substr($i['product_ref'], 2) : null;

            return $i;
        }, Sql::rows($this->pdo, 'SELECT * FROM inquiry_item WHERE inquiry_id = ? ORDER BY position', [$id]));
    }

    /** wa.me wants international digits only; an Iranian 09… mobile becomes 989… . Null when unsure. */
    public static function waNumber(string $phone): ?string
    {
        $digits = self::latinDigits($phone);
        $plus = str_starts_with(ltrim($phone), '+');
        $digits = (string) preg_replace('/\D/', '', $digits);
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }
        if ($plus) {
            return $digits;
        }
        if (preg_match('/^09\d{9}$/', $digits) === 1) {
            return '98' . substr($digits, 1);
        }

        return null;
    }

    /** A tel: value: Latin digits and a leading +, nothing else. */
    public static function telNumber(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', self::latinDigits($phone));

        return (str_starts_with(ltrim($phone), '+') ? '+' : '') . $digits;
    }

    /** Persian and Arabic-Indic digits as Latin (Q2: numbers shown in Latin digits). */
    public static function latinDigits(string $text): string
    {
        return strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
