<?php

declare(strict_types=1);

namespace Nova\Audit;

use Nova\Core\Sql;
use Nova\Security\Actor;
use PDO;

/**
 * Append-only audit trail: who, when, what, before and after (05 §11, §14).
 * Called inside the same transaction as the change it records.
 */
final readonly class AuditLog
{
    public function __construct(private PDO $pdo) {}

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(?Actor $actor, string $action, string $entityType, ?int $entityId, ?string $label, ?array $before = null, ?array $after = null): void
    {
        Sql::run(
            $this->pdo,
            'INSERT INTO audit_event (user_id, action, entity_type, entity_id, entity_label, before_text, after_text) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$actor?->userId, $action, $entityType, $entityId, $label, self::encode($before), self::encode($after)],
        );
    }

    /** @param array<string, mixed>|null $data */
    private static function encode(?array $data): ?string
    {
        return $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
