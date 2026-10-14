<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Core\Config;
use Nova\Core\Environment;
use RuntimeException;

/**
 * Inquiry settings from the server configuration (05 §13). Everything here is
 * operator configuration; the notification recipient is not: it is an Admin
 * setting the Owner controls (Gate 2 decision D, `InquiryRecipient`).
 *
 * - `inquiry.key`: the secret that signs fill-time tokens and keys the
 *   address HMAC. Required outside development; development derives one from
 *   the database password so the dev stack needs no new secret. Never stored
 *   in a file of the repository.
 * - `inquiry.min_fill_seconds`, `inquiry.limits.hour|day`: abuse safety caps,
 *   not performance budgets (05 §12, D14).
 * - `smtp.host|port|user|password|encryption`, `mail.from`: the SMTP mailbox
 *   (a launch input; the dev stack uses the local mail catcher).
 */
final readonly class InquiryConfig
{
    public function __construct(
        public string $key,
        public int $minFillSeconds = 3,
        public int $maxTokenAge = 86_400,
        public int $perHour = 10,
        public int $perDay = 30,
        public SmtpSettings $smtp = new SmtpSettings('', 25),
        public string $from = 'no-reply@nova.localhost',
    ) {
        if (\strlen($key) < 32) {
            throw new RuntimeException('inquiry.key must be at least 32 characters.');
        }
    }

    public static function fromConfig(Config $config): self
    {
        $inquiry = $config->get('inquiry');
        $inquiry = \is_array($inquiry) ? $inquiry : [];
        $key = \is_string($inquiry['key'] ?? null) ? $inquiry['key'] : (string) getenv('NOVA_INQUIRY_KEY');
        if ($key === '') {
            if ($config->environment !== Environment::Development) {
                throw new RuntimeException('Configuration needs inquiry.key (at least 32 random characters) outside development.');
            }
            $key = hash_hmac('sha256', 'nova-inquiry-key-v1', $config->database->password);
        }
        $limits = \is_array($inquiry['limits'] ?? null) ? $inquiry['limits'] : [];
        $smtp = $config->get('smtp');
        $smtp = \is_array($smtp) ? $smtp : [];
        $mail = $config->get('mail');
        $mail = \is_array($mail) ? $mail : [];

        return new self(
            $key,
            self::int($inquiry['min_fill_seconds'] ?? null, 3),
            self::int($inquiry['max_token_age'] ?? null, 86_400),
            self::int($limits['hour'] ?? null, 10),
            self::int($limits['day'] ?? null, 30),
            new SmtpSettings(
                \is_string($smtp['host'] ?? null) ? $smtp['host'] : '',
                self::int($smtp['port'] ?? null, 25),
                \is_string($smtp['user'] ?? null) ? $smtp['user'] : '',
                \is_string($smtp['password'] ?? null) ? $smtp['password'] : '',
                \is_string($smtp['encryption'] ?? null) ? $smtp['encryption'] : '',
            ),
            \is_string($mail['from'] ?? null) && $mail['from'] !== '' ? $mail['from'] : 'no-reply@nova.localhost',
        );
    }

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }
}
