<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Core\Sql;
use PDO;

/**
 * Which content changes a release contains (05 §7; the A7 change list and
 * the "unpublished changes" count). Exact, with no look-back window:
 *
 * - Inside its consistent snapshot, a publish selects the content audit
 *   events it can see that no earlier release recorded, and records them
 *   (publish_change, append-only) when it goes live.
 * - Releases are numbered in snapshot order (publish_record ids; one
 *   publish at a time), and a committed change stays visible in every later
 *   snapshot. So a change is in release R exactly when it was recorded by R
 *   or by an earlier release. A change whose transaction was still open at
 *   R's snapshot is recorded by the next release that sees it, however many
 *   other changes committed in between.
 * - After a rollback the live release is an earlier one, and the changes
 *   recorded by later releases count as unpublished again.
 * - Sign-in, account, publish, migration and inquiry events are not content:
 *   inquiries are never published (05 §13).
 */
final class ChangeTracking
{
    /** Audit events that change published content (alias `a`): not sign-ins, accounts, publishing or migrations. */
    public const string CONTENT = "a.action NOT LIKE 'auth.%' AND a.action NOT LIKE 'user.%' AND a.action NOT LIKE 'publish%' AND a.action NOT LIKE 'migration.%' AND a.action NOT LIKE 'inquiry.%'";

    private const int CHUNK = 500;

    /**
     * Run inside the publish snapshot: the content changes it contains that no earlier release recorded.
     *
     * @return list<int>
     */
    public static function newInSnapshot(PDO $pdo): array
    {
        return array_map(intval(...), Sql::column($pdo, 'SELECT a.id FROM audit_event a WHERE ' . self::CONTENT . ' AND NOT EXISTS (SELECT 1 FROM publish_change pc WHERE pc.audit_event_id = a.id) ORDER BY a.id'));
    }

    /** @param list<int> $changes recorded as first contained in release record `$record` (in its record transaction) */
    public static function record(PDO $pdo, int $record, array $changes): void
    {
        foreach (array_chunk($changes, self::CHUNK) as $chunk) {
            $rows = implode(', ', array_fill(0, \count($chunk), '(?, ?)'));
            $params = [];
            foreach ($chunk as $id) {
                array_push($params, $id, $record);
            }
            Sql::run($pdo, "INSERT INTO publish_change (audit_event_id, publish_record_id) VALUES {$rows}", $params);
        }
    }

    /**
     * The condition (alias `a`) selecting content changes the live release
     * does not contain, with its parameters. A live release published before
     * tracking existed (snapshot_audit_id NULL) falls back to the time it was
     * first recorded, which is approximate.
     *
     * @return array{string, list<int|string>}
     */
    public static function notLive(PDO $pdo): array
    {
        $live = Sql::row($pdo, "SELECT id, finished_at, went_live_at, snapshot_audit_id FROM publish_record WHERE status = 'live' ORDER BY went_live_at DESC, id DESC LIMIT 1");
        if ($live === null) {
            return [self::CONTENT, []];
        }
        if ($live['snapshot_audit_id'] === null) {
            return [self::CONTENT . ' AND a.occurred_at > ?', [(string) ($live['finished_at'] ?? $live['went_live_at'])]];
        }

        return [self::CONTENT . ' AND NOT EXISTS (SELECT 1 FROM publish_change pc WHERE pc.audit_event_id = a.id AND pc.publish_record_id <= ?)', [(int) $live['id']]];
    }
}
