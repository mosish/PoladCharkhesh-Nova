<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Audit\AuditLog;
use Nova\Core\Environment;
use Nova\Core\Sql;
use Nova\Security\Actor;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Draft → Publish (05 §7): lock → snapshot → validate → build → verify →
 * switch → record → prune to 5 (D13). The journal records every step; an
 * interrupted publish is rolled back (not yet switched) or finished (switched)
 * by recover(), which runs at the start of every publish and Admin request.
 * Rollback is an instant switch back to an earlier release still on disk.
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

    public function publish(?Actor $actor): PublishResult
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
            Sql::run($this->pdo, 'INSERT INTO publish_record (release_id, strategy, environment, published_by, previous_release_id) VALUES (?, ?, ?, ?, ?)', [$release, $this->switcher->name(), $this->environment->value, $actor?->userId, $previous]);
            $record = (int) $this->pdo->lastInsertId();
            $this->journal->write(['release' => $release, 'record' => $record, 'strategy' => $this->switcher->name(), 'previous' => $previous, 'step' => 'started', 'at' => time()]);
            $this->tick('started');

            try {
                return $this->run($actor, $release, $record);
            } catch (SimulatedCrash $crash) {
                throw $crash;                       // a killed process does no cleanup; recover() handles it
            } catch (Throwable $e) {
                $this->recoverLocked($e->getMessage());

                throw $e;
            }
        } finally {
            $this->unlock();
        }
    }

    /** Finishes or rolls back an interrupted publish. Safe to call at any time. */
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
    public function rollback(?Actor $actor): string
    {
        $this->lock();
        try {
            $this->recoverLocked();
            $current = $this->switcher->current() ?? throw new RuntimeException('Nothing is published yet.');
            $target = null;
            // Back means back: the newest release that was superseded, never one that was itself rolled back.
            foreach (Sql::column($this->pdo, "SELECT release_id FROM publish_record WHERE status = 'superseded' AND went_live_at IS NOT NULL ORDER BY went_live_at DESC, id DESC") as $id) {
                if ($id !== $current && $this->switcher->has((string) $id)) {
                    $target = (string) $id;
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
            $this->switcher->activate($target);
            $this->pdo->beginTransaction();
            Sql::run($this->pdo, "UPDATE publish_record SET status = 'rolled_back' WHERE release_id = ?", [$current]);
            Sql::run($this->pdo, "UPDATE publish_record SET status = 'live', went_live_at = UTC_TIMESTAMP(6) WHERE release_id = ?", [$target]);
            (new AuditLog($this->pdo))->record($actor, 'publish.rollback', 'release', null, $target, ['live' => $current], ['live' => $target]);
            $this->pdo->commit();

            return $target;
        } finally {
            $this->unlock();
        }
    }

    private function run(?Actor $actor, string $release, int $record): PublishResult
    {
        // Snapshot + validate: one consistent read of the whole catalogue.
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $data = SiteData::load($this->pdo, $this->config->includeSamples, $this->contactFile);
            $check = (new Validator($this->pdo, $this->environment === Environment::Development))->check($data);
        } finally {
            $this->pdo->exec('COMMIT');
        }
        if ($check['blockers'] !== []) {
            $this->finishRecord($record, 'failed', ['blockers' => $check['blockers'], 'warnings' => $check['warnings']]);
            $this->journal->clear();

            return new PublishResult('failed', $release, $check['blockers'], $check['warnings']);
        }

        $dir = $this->switcher->prepare($release);
        $this->journal->step('prepared');
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
        $this->journal->step('verified');
        $this->tick('verified');

        $this->switcher->activate($release);
        $this->journal->step('switched');
        $this->tick('switched');

        $this->recordLive($actor, $release, $record, ['products' => \count($data->products), 'files' => \count($files), 'warnings' => $check['warnings']], $data);
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

        if (!$switched) {
            if ($this->switcher->has($release)) {
                $this->switcher->discard($release);
            }
            $this->finishRecord($entry['record'], 'failed', ['blockers' => ['interrupted at step "' . $entry['step'] . '"' . ($cause !== null ? ": {$cause}" : '') . '; rolled back']]);
            $this->journal->clear();

            return "Rolled back the interrupted publish {$release}.";
        }

        if ($entry['step'] !== 'recorded') {
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            try {
                $data = SiteData::load($this->pdo, $this->config->includeSamples, $this->contactFile);
            } finally {
                $this->pdo->exec('COMMIT');
            }
            $this->recordLive(null, $release, $entry['record'], ['recovered' => true], $data);
        }
        $this->prune();
        $this->journal->clear();

        return "Finished the interrupted publish {$release}.";
    }

    /** @param array<string, mixed> $summary */
    private function recordLive(?Actor $actor, string $release, int $record, array $summary, SiteData $data): void
    {
        $this->pdo->beginTransaction();
        try {
            Sql::run($this->pdo, "UPDATE publish_record SET status = 'superseded' WHERE status = 'live' AND id <> ?", [$record]);
            $manifest = @hash_file('sha256', $this->manifestPath($release));
            Sql::run($this->pdo, "UPDATE publish_record SET status = 'live', went_live_at = UTC_TIMESTAMP(6), finished_at = UTC_TIMESTAMP(6), manifest_sha256 = ?, summary_text = ? WHERE id = ?", [$manifest === false ? null : $manifest, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $record]);
            // Public identity is frozen from the first publish on (slugs, 410/301 on archive).
            foreach ($data->products as $p) {
                Sql::run($this->pdo, 'UPDATE product SET first_published_at = COALESCE(first_published_at, UTC_TIMESTAMP()) WHERE id = ?', [$p['id']]);
                foreach ($p['items'] as $item) {
                    if ($item['kind'] === 'variant') {
                        Sql::run($this->pdo, 'UPDATE product_variant SET first_published_at = COALESCE(first_published_at, UTC_TIMESTAMP()) WHERE slug = ?', [$item['slug']]);
                    }
                }
            }
            (new AuditLog($this->pdo))->record($actor, 'publish', 'release', null, $release, null, $summary);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
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
