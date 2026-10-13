<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Audit\AuditLog;
use Nova\Core\Environment;
use Nova\Core\Sql;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Draft → Publish (05 §7): lock → snapshot → validate → build → verify →
 * switch → record → prune to 5 (D13). The journal records every step; an
 * interrupted publish is rolled back (not yet switched) or finished (switched)
 * by recover(), which runs at the start of every publish and Admin request.
 * Rollback is an instant switch back to an earlier release still on disk; it
 * is journalled too, so a crash between its switch and its record is finished.
 *
 * Recovery records what the switched release actually contains (its identity
 * and summary are journalled before the switch), never a fresh read of a
 * database that may have changed since the build.
 *
 * @phpstan-import-type Identity from PublishJournal
 * @phpstan-import-type SnapshotPoint from PublishJournal
 */
final class PublishEngine
{
    public const int KEEP = 5;
    private const string LOCK = 'nova_publish';

    /** @var (\Closure(string): void)|null */
    private ?\Closure $fault = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ReleaseSwitcher $switcher,
        private readonly PublishJournal $journal,
        private readonly SiteBuilder $builder,
        private readonly BuildConfig $config,
        private readonly Environment $environment,
        private readonly string $contactFile,
    ) {}

    /** Tests: called after every journal step; throw SimulatedCrash to stand for a killed process. */
    public function injectFault(\Closure $fault): void
    {
        $this->fault = $fault;
    }

    public function publish(Initiator $by): PublishResult
    {
        $this->lock();
        try {
            $this->recoverLocked();

            $release = 'R-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2));
            $wait = $this->switcher->waitBefore($release);
            if ($wait > 0) {
                return new PublishResult('waiting', null, waitSeconds: $wait);
            }
            $previous = $this->switcher->current();
            Sql::run($this->pdo, 'INSERT INTO publish_record (release_id, strategy, environment, published_by, previous_release_id) VALUES (?, ?, ?, ?, ?)', [$release, $this->switcher->name(), $this->environment->value, $by->actor?->userId, $previous]);
            $record = (int) $this->pdo->lastInsertId();
            $this->journal->write(['kind' => 'publish', 'release' => $release, 'record' => $record, 'strategy' => $this->switcher->name(), 'previous' => $previous, 'step' => 'started', 'at' => time(), 'by' => $by->toArray()]);
            $this->tick('started');

            try {
                return $this->run($by, $release, $record);
            } catch (SimulatedCrash $crash) {
                throw $crash;                       // a killed process does no cleanup; recover() handles it
            } catch (Throwable $e) {
                $this->recoverAfter($e);

                throw $e;
            }
        } finally {
            $this->unlock();
        }
    }

    /** The release visitors are served now (what the switch says, not the records), or null before the first publish. */
    public function live(): ?string
    {
        return $this->switcher->current();
    }

    /** Whether an interrupted publish or rollback is still waiting to be finished or rolled back. */
    public function pendingRecovery(): bool
    {
        return $this->journal->read() !== null;
    }

    /** Finishes or rolls back an interrupted publish or rollback. Safe to call at any time, any number of times. */
    public function recover(): ?string
    {
        $this->lock();
        try {
            return $this->recoverLocked();
        } finally {
            $this->unlock();
        }
    }

    /** Switches back to the most recent earlier release still on disk. */
    public function rollback(Initiator $by): string
    {
        $this->lock();
        try {
            $this->recoverLocked();
            $current = $this->switcher->current() ?? throw new RuntimeException('Nothing is published yet.');
            $target = null;
            $targetRecord = 0;
            // Back means back: the newest release that was superseded, never one that was itself rolled back.
            foreach (Sql::rows($this->pdo, "SELECT id, release_id FROM publish_record WHERE status = 'superseded' AND went_live_at IS NOT NULL ORDER BY went_live_at DESC, id DESC") as $row) {
                $id = (string) $row['release_id'];
                if ($id !== $current && $this->switcher->has($id)) {
                    $target = $id;
                    $targetRecord = (int) $row['id'];
                    break;
                }
            }
            if ($target === null) {
                throw new RuntimeException('No earlier release is available to roll back to.');
            }
            $wait = $this->switcher->waitBefore($target);
            if ($wait > 0) {
                throw new RuntimeException("Rollback can run in {$wait} s (slot grace period).");
            }
            // Journalled before the switch: a crash after it is finished by recover(), not left half-recorded.
            $this->journal->write(['kind' => 'rollback', 'release' => $target, 'record' => $targetRecord, 'strategy' => $this->switcher->name(), 'previous' => $current, 'step' => 'started', 'at' => time(), 'by' => $by->toArray()]);
            $this->tick('rollback-started');
            try {
                $this->switcher->activate($target);
            } catch (SimulatedCrash $crash) {
                throw $crash;
            } catch (Throwable $e) {
                $this->recoverAfter($e);

                throw $e;
            }
            $this->journal->step('switched');
            $this->tick('rollback-switched');
            $this->recordRollback($by, $current, $target, $targetRecord, null);
            $this->journal->clear();

            return $target;
        } finally {
            $this->unlock();
        }
    }

    private function run(Initiator $by, string $release, int $record): PublishResult
    {
        // Snapshot + validate: one consistent read of the whole catalogue.
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $data = SiteData::load($this->pdo, $this->config->includeSamples, $this->contactFile);
            $check = (new Validator($this->pdo, $this->environment === Environment::Development))->check($data);
            $identity = $this->identity($data);
            $point = $this->snapshotPoint();
        } finally {
            $this->pdo->exec('COMMIT');
        }
        if ($check['blockers'] !== []) {
            $this->finishRecord($record, 'failed', ['blockers' => $check['blockers'], 'warnings' => $check['warnings']]);
            $this->journal->clear();

            return new PublishResult('failed', $release, $check['blockers'], $check['warnings']);
        }

        $dir = $this->switcher->prepare($release);
        $this->journal->step('prepared', ['identity' => $identity, 'snapshot' => $point]);
        $this->tick('prepared');

        $files = $this->builder->build($data, $dir);
        $this->journal->step('built');
        $this->tick('built');

        $problems = [...self::verifyManifest($dir), ...Validator::checkOutput($dir, $this->config)];
        if ($problems !== []) {
            $this->switcher->discard($release);
            $this->finishRecord($record, 'failed', ['blockers' => $problems, 'warnings' => $check['warnings']]);
            $this->journal->clear();

            return new PublishResult('failed', $release, $problems, $check['warnings']);
        }
        $summary = ['products' => \count($data->products), 'files' => \count($files), 'warnings' => $check['warnings']];
        $this->journal->step('verified', ['summary' => $summary]);
        $this->tick('verified');

        $this->switcher->activate($release);
        $this->journal->step('switched');
        $this->tick('switched');

        $this->recordLive($by, $release, $record, $summary, $identity, $point);
        $this->journal->step('recorded');
        $this->tick('recorded');

        $this->prune();
        $this->journal->clear();

        return new PublishResult('live', $release, [], $check['warnings']);
    }

    private function recoverLocked(?string $cause = null): ?string
    {
        $entry = $this->journal->read();
        if ($entry === null) {
            return null;
        }
        $release = $entry['release'];
        // The truth is what is live, not the last journal line: a switch may have happened just before a crash.
        $switched = $this->switcher->current() === $release;
        $startedBy = $entry['by'] ?? null;

        if (($entry['kind'] ?? 'publish') === 'rollback') {
            if ($switched && $this->recordStatus($entry['record']) !== 'live') {
                $this->recordRollback(Initiator::recovery(), (string) $entry['previous'], $release, $entry['record'], $startedBy);
            }
            $this->journal->clear();

            return $switched ? "Finished the interrupted rollback to {$release}." : "The interrupted rollback to {$release} never switched; nothing changed.";
        }

        if (!$switched) {
            if ($this->switcher->has($release)) {
                $this->switcher->discard($release);
            }
            $this->finishRecord($entry['record'], 'failed', ['blockers' => ['interrupted at step "' . $entry['step'] . '"' . ($cause !== null ? ": {$cause}" : '') . '; rolled back']]);
            $this->journal->clear();

            return "Rolled back the interrupted publish {$release}.";
        }

        // Idempotent: a recovery that itself died after recording must not record (or audit) twice.
        if ($this->recordStatus($entry['record']) !== 'live') {
            $summary = [...($entry['summary'] ?? []), 'recovered' => true];
            if ($startedBy !== null) {
                $summary['started_by'] = $startedBy;
            }
            $this->recordLive(Initiator::recovery(), $release, $entry['record'], $summary, $entry['identity'] ?? null, self::point($entry['snapshot'] ?? null));
        }
        $this->prune();
        $this->journal->clear();

        return "Finished the interrupted publish {$release}.";
    }

    /**
     * @param array<string, mixed> $summary
     * @param Identity|null $identity what the release publishes; null only for a journal written without it
     * @param SnapshotPoint|null $point the snapshot's newest audit id and the changes it contains (null: not tracked)
     */
    private function recordLive(Initiator $by, string $release, int $record, array $summary, ?array $identity, ?array $point = null): void
    {
        if ($identity === null) {
            $summary['warnings'] = [...(\is_array($summary['warnings'] ?? null) ? $summary['warnings'] : []), 'the release identity was not journalled; first_published_at was not updated'];
        }
        $this->pdo->beginTransaction();
        try {
            Sql::run($this->pdo, "UPDATE publish_record SET status = 'superseded' WHERE status = 'live' AND id <> ?", [$record]);
            $manifest = @hash_file('sha256', $this->manifestPath($release));
            Sql::run($this->pdo, "UPDATE publish_record SET status = 'live', went_live_at = UTC_TIMESTAMP(6), finished_at = UTC_TIMESTAMP(6), manifest_sha256 = ?, summary_text = ?, snapshot_audit_id = ? WHERE id = ?", [
                $manifest === false ? null : $manifest, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $point['audit'] ?? null, $record,
            ]);
            if ($point !== null) {
                ChangeTracking::record($this->pdo, $record, $point['changes']);
            }
            // Public identity is frozen from the first publish on (slugs, 410/301 on archive).
            foreach ($identity['products'] ?? [] as $id) {
                Sql::run($this->pdo, 'UPDATE product SET first_published_at = COALESCE(first_published_at, UTC_TIMESTAMP()) WHERE id = ?', [$id]);
            }
            foreach ($identity['variants'] ?? [] as $id) {
                Sql::run($this->pdo, 'UPDATE product_variant SET first_published_at = COALESCE(first_published_at, UTC_TIMESTAMP()) WHERE id = ?', [$id]);
            }
            (new AuditLog($this->pdo))->record($by->actor, 'publish', 'release', null, $release, null, [...$summary, 'by' => $by->toArray()]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /** @param array<string, int|string>|null $startedBy who started a rollback that recovery is finishing */
    private function recordRollback(Initiator $by, string $from, string $target, int $targetRecord, ?array $startedBy): void
    {
        $after = ['live' => $target, 'by' => $by->toArray()];
        if ($startedBy !== null) {
            $after['started_by'] = $startedBy;
        }
        $this->pdo->beginTransaction();
        try {
            Sql::run($this->pdo, "UPDATE publish_record SET status = 'rolled_back' WHERE release_id = ? AND status = 'live'", [$from]);
            Sql::run($this->pdo, "UPDATE publish_record SET status = 'live', went_live_at = UTC_TIMESTAMP(6) WHERE id = ?", [$targetRecord]);
            (new AuditLog($this->pdo))->record($by->actor, 'publish.rollback', 'release', null, $target, ['live' => $from], $after);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * What a release publishes, read inside the snapshot: the internal ids of
     * its Products and listed variants (never shown publicly).
     *
     * @return Identity
     */
    private function identity(SiteData $data): array
    {
        $products = [];
        $variants = [];
        foreach ($data->products as $p) {
            $products[] = $p['id'];
            foreach ($p['items'] as $item) {
                if ($item['kind'] === 'variant') {
                    $variants[] = Sql::int($this->pdo, 'SELECT id FROM product_variant WHERE slug = ?', [$item['slug']]);
                }
            }
        }

        return ['products' => $products, 'variants' => $variants];
    }

    /**
     * Read inside the snapshot: its newest audit id, and exactly the content
     * changes it contains that no earlier release recorded (ChangeTracking).
     * A change still open at the snapshot is not among them, whatever its id.
     *
     * @return SnapshotPoint
     */
    private function snapshotPoint(): array
    {
        return ['audit' => Sql::int($this->pdo, 'SELECT COALESCE(MAX(id), 0) FROM audit_event'), 'changes' => ChangeTracking::newInSnapshot($this->pdo)];
    }

    /**
     * A journalled snapshot point, or null when the journal predates change
     * tracking (the release is then recorded without it).
     *
     * @return SnapshotPoint|null
     */
    private static function point(mixed $point): ?array
    {
        if (!\is_array($point) || !\is_int($point['audit'] ?? null) || !\is_array($point['changes'] ?? null)) {
            return null;
        }

        return ['audit' => $point['audit'], 'changes' => array_values(array_filter($point['changes'], \is_int(...)))];
    }

    /**
     * The error path of a publish or rollback: finish or undo what the failed
     * operation did. If that recovery fails too, the live state is unknown, so
     * the caller gets RecoveryFailed (carrying both errors), never the
     * original error as if the site were in a known state.
     */
    private function recoverAfter(Throwable $failure): void
    {
        try {
            $this->recoverLocked($failure->getMessage());
        } catch (Throwable $recovery) {
            throw new RecoveryFailed($failure, $recovery);
        }
    }

    private function recordStatus(int $record): ?string
    {
        $status = Sql::scalar($this->pdo, 'SELECT status FROM publish_record WHERE id = ?', [$record]);

        return $status === null ? null : (string) $status;
    }

    /** Keeps the KEEP most recent releases that went live (D13); never the live one. */
    private function prune(): void
    {
        $current = $this->switcher->current();
        $kept = 0;
        foreach (Sql::column($this->pdo, "SELECT release_id FROM publish_record WHERE went_live_at IS NOT NULL AND status <> 'pruned' ORDER BY went_live_at DESC, id DESC") as $id) {
            $id = (string) $id;
            if ($id === $current || $kept < self::KEEP - 1) {
                $kept += $id === $current ? 0 : 1;
                continue;
            }
            if ($this->switcher->has($id)) {
                $this->switcher->discard($id);
            }
            Sql::run($this->pdo, "UPDATE publish_record SET status = 'pruned' WHERE release_id = ?", [$id]);
        }
    }

    /** @param array<string, mixed> $summary */
    private function finishRecord(int $record, string $status, array $summary): void
    {
        Sql::run($this->pdo, 'UPDATE publish_record SET status = ?, finished_at = UTC_TIMESTAMP(6), summary_text = ? WHERE id = ?', [$status, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $record]);
    }

    /** @return list<string> */
    public static function verifyManifest(string $dir): array
    {
        $json = @file_get_contents("{$dir}/manifest.json");
        $manifest = $json === false ? null : json_decode($json, true);
        if (!\is_array($manifest) || !\is_array($manifest['files'] ?? null)) {
            return ['the release manifest is missing or unreadable'];
        }
        $problems = [];
        foreach ($manifest['files'] as $file => $meta) {
            $path = "{$dir}/{$file}";
            if (!is_file($path) || !\is_array($meta) || hash_file('sha256', $path) !== ($meta['sha256'] ?? null)) {
                $problems[] = "release file {$file} is missing or does not match the manifest";
            }
        }

        return $problems;
    }

    private function manifestPath(string $release): string
    {
        return $this->switcher->releaseDir($release) . '/manifest.json';
    }

    private function tick(string $step): void
    {
        if ($this->fault !== null) {
            ($this->fault)($step);
        }
    }

    private function lock(): void
    {
        if (Sql::int($this->pdo, 'SELECT GET_LOCK(?, 0)', [self::LOCK]) !== 1) {
            throw new RuntimeException('Another publish is in progress.');
        }
    }

    private function unlock(): void
    {
        Sql::run($this->pdo, 'SELECT RELEASE_LOCK(?)', [self::LOCK]);
    }
}
