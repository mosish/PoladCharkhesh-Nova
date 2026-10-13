<?php

declare(strict_types=1);

namespace Nova\Admin;

use Nova\Core\Environment;
use Nova\Core\Sql;
use Nova\Publish\BuildConfig;
use Nova\Publish\SiteData;
use Nova\Publish\Validator;
use PDO;

/**
 * What the Publish screen (frozen A7) and the top bar show: the live release,
 * the changes recorded since it went live (from the audit trail, with who
 * made them), a validation dry run (the same SiteData + Validator the engine
 * runs, without building), and the retained history. Publishing and rolling
 * back go through the PublishEngine.
 */
final readonly class PublishAdmin
{
    /** Audit actions that are not changes to published content. */
    private const string NOT_CONTENT = "a.action NOT LIKE 'auth.%' AND a.action NOT LIKE 'user.%' AND a.action NOT LIKE 'publish%' AND a.action NOT LIKE 'migration.%'";

    public function __construct(
        private PDO $pdo,
        private BuildConfig $config,
        private Environment $environment,
        private string $contactFile,
    ) {}

    /** @return array{release: string, at: string, by: ?string, strategy: string}|null */
    public function live(): ?array
    {
        $row = Sql::row($this->pdo, "SELECT r.release_id, r.went_live_at, r.strategy, u.display_name FROM publish_record r LEFT JOIN app_user u ON u.id = r.published_by WHERE r.status = 'live' ORDER BY r.went_live_at DESC LIMIT 1");

        return $row === null ? null : ['release' => (string) $row['release_id'], 'at' => (string) $row['went_live_at'], 'by' => $row['display_name'] === null ? null : (string) $row['display_name'], 'strategy' => (string) $row['strategy']];
    }

    public function unpublishedCount(): int
    {
        [$sql, $params] = $this->sinceLive();

        return Sql::int($this->pdo, "SELECT COUNT(*) FROM audit_event a WHERE {$sql}", $params);
    }

    /** @return list<array<string, mixed>> */
    public function changes(int $limit = 100): array
    {
        [$sql, $params] = $this->sinceLive();

        return Activity::describe(Sql::rows($this->pdo, "SELECT a.*, u.display_name FROM audit_event a LEFT JOIN app_user u ON u.id = a.user_id WHERE {$sql} ORDER BY a.id DESC LIMIT {$limit}", $params));
    }

    /** @return array{blockers: list<string>, warnings: list<string>} */
    public function check(): array
    {
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $data = SiteData::load($this->pdo, $this->config->includeSamples, $this->contactFile);

            return (new Validator($this->pdo, $this->environment === Environment::Development))->check($data);
        } finally {
            $this->pdo->exec('COMMIT');
        }
    }

    /** @return list<array{release: string, status: string, at: string, by: ?string, rollback_target: bool}> */
    public function history(?string $rollbackTarget): array
    {
        return array_map(static fn(array $r): array => [
            'release' => (string) $r['release_id'], 'status' => (string) $r['status'], 'at' => (string) $r['went_live_at'],
            'by' => $r['display_name'] === null ? null : (string) $r['display_name'], 'rollback_target' => $r['release_id'] === $rollbackTarget,
        ], Sql::rows($this->pdo, "SELECT r.release_id, r.status, r.went_live_at, u.display_name FROM publish_record r LEFT JOIN app_user u ON u.id = r.published_by WHERE r.went_live_at IS NOT NULL AND r.status <> 'pruned' ORDER BY r.went_live_at DESC, r.id DESC LIMIT 5"));
    }

    /** The release a rollback would return to (the engine's own rule: the newest superseded one). */
    public function rollbackTarget(): ?string
    {
        $id = Sql::scalar($this->pdo, "SELECT release_id FROM publish_record WHERE status = 'superseded' AND went_live_at IS NOT NULL ORDER BY went_live_at DESC, id DESC LIMIT 1");

        return $id === null ? null : (string) $id;
    }

    /** @return array{string, list<string>} */
    private function sinceLive(): array
    {
        $since = Sql::scalar($this->pdo, "SELECT MAX(went_live_at) FROM publish_record WHERE status = 'live'");

        return $since === null ? [self::NOT_CONTENT, []] : [self::NOT_CONTENT . ' AND a.occurred_at > ?', [(string) $since]];
    }
}
