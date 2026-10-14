<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Audit\AuditLog;
use Nova\Catalogue\CatalogueError;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Security\Actor;
use PDO;

/**
 * Where inquiry notifications go (Gate 2 decision D, frozen A8): an Admin
 * setting only the Owner may change, never a value in code or config. The
 * real address is a launch input. While it is empty every notification fails
 * visibly and stays retryable; no inquiry is affected.
 */
final readonly class InquiryRecipient
{
    public const string KEY = 'inquiry.notify_to';

    public function __construct(private PDO $pdo) {}

    public function get(): ?string
    {
        $value = Sql::scalar($this->pdo, 'SELECT value_text FROM setting WHERE setting_key = ?', [self::KEY]);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public static function valid(string $email): bool
    {
        return \strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && preg_match('/[\s,;<>"]/', $email) !== 1;
    }

    /** Owner only; '' clears it. */
    public function set(Actor $actor, string $email): void
    {
        if (!$actor->isOwner()) {
            throw CatalogueError::forbidden('Only the Owner sets where inquiry notifications go.');
        }
        $email = trim($email);
        if ($email !== '' && !self::valid($email)) {
            throw CatalogueError::invalid('The notification address is not a valid email address.');
        }
        Transaction::run($this->pdo, function () use ($actor, $email): void {
            $before = $this->get();
            if ($before === ($email === '' ? null : $email)) {
                return;
            }
            Sql::run($this->pdo, 'INSERT INTO setting (setting_key, value_text, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_by = VALUES(updated_by)', [self::KEY, $email, $actor->userId]);
            (new AuditLog($this->pdo))->record($actor, 'inquiry.recipient', 'setting', null, self::KEY, ['to' => $before], ['to' => $email === '' ? null : $email]);
        });
    }
}
