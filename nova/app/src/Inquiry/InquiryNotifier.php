<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Core\Sql;
use PDO;
use Throwable;

/**
 * Notifies the Owner-configured address of a stored inquiry (05 §13). Email
 * is only a notification: the inquiry is already committed, and a failure
 * leaves it `failed` with the reason, counted on the dashboard and retried
 * from the Admin (Retry) or `app/bin/nova inquiry:notify`.
 *
 * - A send is claimed first (a conditional UPDATE that must change one row),
 *   so concurrent retries never send the same inquiry twice; a claim left by
 *   a crashed request expires after `$staleSeconds`.
 * - The email carries the reference, language, time, release and requested
 *   designations with their match, and a link to the Admin. It never carries
 *   the visitor's name, phone or message: mailbox copies would outlive the
 *   24-month retention and deletion on request (E-12).
 */
final readonly class InquiryNotifier
{
    public function __construct(
        private PDO $pdo,
        private Mailer $mailer,
        private string $adminUrl,
        private int $staleSeconds = 600,
    ) {}

    /** @return 'sent'|'failed'|'skipped' skipped: already sent, or another request is sending it */
    public function deliver(int $id): string
    {
        $claimed = Sql::run(
            $this->pdo,
            "UPDATE inquiry SET notify_status = 'sending', notify_claimed_at = UTC_TIMESTAMP(6), notify_attempts = notify_attempts + 1
             WHERE id = ? AND (notify_status IN ('pending', 'failed') OR (notify_status = 'sending' AND notify_claimed_at < UTC_TIMESTAMP(6) - INTERVAL ? SECOND))",
            [$id, $this->staleSeconds],
        )->rowCount();
        if ($claimed !== 1) {
            return 'skipped';
        }

        try {
            $to = (new InquiryRecipient($this->pdo))->get();
            if ($to === null) {
                throw new \RuntimeException('No notification address is set (Admin → Inquiries → notification address, Owner only).');
            }
            [$subject, $body] = $this->message($id);
            $this->mailer->send($to, $subject, $body);
        } catch (Throwable $e) {
            Sql::run($this->pdo, "UPDATE inquiry SET notify_status = 'failed', notify_claimed_at = NULL, notify_error = ? WHERE id = ? AND notify_status = 'sending'", [mb_substr($e->getMessage(), 0, 255), $id]);

            return 'failed';
        }
        Sql::run($this->pdo, "UPDATE inquiry SET notify_status = 'sent', notify_claimed_at = NULL, notified_at = UTC_TIMESTAMP(6), notify_error = NULL WHERE id = ? AND notify_status = 'sending'", [$id]);

        return 'sent';
    }

    /**
     * Retries every pending or failed notification, oldest first.
     *
     * @return array{sent: int, failed: int}
     */
    public function retryAll(int $limit = 100): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        foreach (Sql::column($this->pdo, "SELECT id FROM inquiry WHERE notify_status IN ('pending', 'failed') OR (notify_status = 'sending' AND notify_claimed_at < UTC_TIMESTAMP(6) - INTERVAL ? SECOND) ORDER BY id LIMIT {$limit}", [$this->staleSeconds]) as $id) {
            $outcome = $this->deliver((int) $id);
            if ($outcome !== 'skipped') {
                ++$result[$outcome];
            }
        }

        return $result;
    }

    /** @return array{string, string} subject, body */
    public function message(int $id): array
    {
        $q = Sql::row($this->pdo, 'SELECT id, received_at, lang, release_id, match_state FROM inquiry WHERE id = ?', [$id]) ?? throw new \RuntimeException("Inquiry {$id} does not exist.");
        $ref = InquiryStore::reference($id);
        $lines = [];
        foreach (Sql::rows($this->pdo, 'SELECT requested_text, match_type, via_spelling, product_designation, variant_designation, chosen_designation FROM inquiry_item WHERE inquiry_id = ? ORDER BY position', [$id]) as $item) {
            $resolved = $item['variant_designation'] ?? $item['chosen_designation'] ?? $item['product_designation'];
            $lines[] = '  - ' . (string) $item['requested_text'] . ' — ' . ($item['match_type'] ?? 'not matched') . ((int) $item['via_spelling'] === 1 ? ' (via spelling)' : '') . ($resolved !== null ? ' → ' . (string) $resolved : '');
        }
        $received = new \DateTimeImmutable((string) $q['received_at'], new \DateTimeZone('UTC'));

        $body = implode("\n", [
            'استعلام تازه‌ای در وب‌سایت ثبت شد. / A new inquiry was received on the website.',
            '',
            "Reference: {$ref}",
            'Received: ' . $received->setTimezone(new \DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i') . ' (Asia/Tehran)',
            'Language: ' . (string) $q['lang'],
            'Requested designations:',
            ...($lines === [] ? ['  (none given; see the message in the Admin)'] : $lines),
            'Matched against release: ' . ($q['release_id'] ?? 'none (' . (string) $q['match_state'] . ')'),
            '',
            'Contact details and the message are shown only in the Admin, never by email:',
            rtrim($this->adminUrl, '/') . "/admin/inquiries/{$id}",
        ]) . "\n";

        return ["استعلام تازه {$ref} / New inquiry {$ref}", $body];
    }
}
