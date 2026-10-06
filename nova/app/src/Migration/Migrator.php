<?php

declare(strict_types=1);

namespace Nova\Migration;

use Nova\Core\Sql;
use PDO;
use Throwable;

/**
 * Forward-only migrations (05 §3; schema review 2026-10-06).
 *
 * - One run at a time (named lock).
 * - Refuses to start when an applied file changed (checksum) or when an
 *   earlier migration never finished: MariaDB cannot roll back DDL, so a
 *   half-applied migration is repaired by restoring the recorded backup.
 * - A non-empty database is backed up (BackupGuard) before the first change;
 *   the reference is stored with every migration of the run.
 */
final class Migrator
{
    public const string EMPTY_DATABASE = 'empty-database';
    private const string LOCK = 'nova_migrate';

    /** @var \Closure(string): void */
    private \Closure $log;

    /** @param (callable(string): void)|null $log */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
        private readonly string $appVersion,
        ?callable $log = null,
    ) {
        $this->log = \Closure::fromCallable($log ?? static function (string $line): void {});
    }

    /** @return list<array{version: int, name: string, state: string}> state: applied | pending */
    public function status(): array
    {
        $applied = $this->applied();
        $rows = [];
        foreach (MigrationFile::scan($this->directory) as $file) {
            $row = $applied[$file->version] ?? null;
            $rows[] = ['version' => $file->version, 'name' => $file->name, 'state' => match (true) {
                $row === null => 'pending',
                $row['finished_at'] === null => 'incomplete',
                $row['checksum'] !== $file->checksum => 'changed',
                default => 'applied',
            }];
        }

        return $rows;
    }

    /** @return list<string> names of the migrations applied by this run */
    public function migrate(BackupGuard $guard): array
    {
        $files = MigrationFile::scan($this->directory);
        $this->lock();
        try {
            $this->ensureStateTable();
            $applied = $this->applied();
            $this->verify($files, $applied);

            $pending = array_values(array_filter($files, static fn(MigrationFile $f): bool => !isset($applied[$f->version])));
            if ($pending === []) {
                return [];
            }

            $backupRef = $this->databaseIsEmpty($applied)
                ? self::EMPTY_DATABASE
                : $guard->ensureBackup($this->pdo, \sprintf('before migration %04d', $pending[0]->version));
            ($this->log)("Backup reference: {$backupRef}");

            $done = [];
            foreach ($pending as $file) {
                $this->apply($file, $backupRef);
                $done[] = \sprintf('%04d_%s', $file->version, $file->name);
                ($this->log)('Applied ' . end($done));
            }

            return $done;
        } finally {
            $this->unlock();
        }
    }

    private function apply(MigrationFile $file, string $backupRef): void
    {
        $this->pdo->prepare('INSERT INTO schema_migrations (version, name, checksum, backup_ref, app_version, started_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))')
            ->execute([$file->version, $file->name, $file->checksum, $backupRef, $this->appVersion]);
        try {
            foreach ($file->statements() as $statement) {
                $this->pdo->exec($statement);
            }
        } catch (Throwable $e) {
            throw new MigrationError(\sprintf('Migration %04d_%s failed and is left incomplete; restore backup "%s" before retrying. Cause: %s', $file->version, $file->name, $backupRef, $e->getMessage()), 0, $e);
        }
        $this->pdo->prepare('UPDATE schema_migrations SET finished_at = UTC_TIMESTAMP(6) WHERE version = ?')->execute([$file->version]);
    }

    /**
     * @param list<MigrationFile> $files
     * @param array<int, array{checksum: string, finished_at: ?string, backup_ref: string}> $applied
     */
    private function verify(array $files, array $applied): void
    {
        $byVersion = [];
        foreach ($files as $file) {
            $byVersion[$file->version] = $file;
        }
        foreach ($applied as $version => $row) {
            if ($row['finished_at'] === null) {
                throw new MigrationError(\sprintf('Migration %04d never finished; restore backup "%s" before migrating again.', $version, $row['backup_ref']));
            }
            $file = $byVersion[$version] ?? throw new MigrationError(\sprintf('Migration %04d is recorded as applied but its file is missing.', $version));
            if ($file->checksum !== $row['checksum']) {
                throw new MigrationError(\sprintf('Applied migration %04d_%s has changed since it ran (checksum mismatch). Applied migrations are immutable; add a new one instead.', $version, $file->name));
            }
        }
    }

    /** @return array<int, array{checksum: string, finished_at: ?string, backup_ref: string}> */
    private function applied(): array
    {
        if (!$this->tableExists('schema_migrations')) {
            return [];
        }
        $rows = [];
        foreach (Sql::rows($this->pdo, 'SELECT version, checksum, finished_at, backup_ref FROM schema_migrations ORDER BY version') as $row) {
            $rows[(int) $row['version']] = [
                'checksum' => (string) $row['checksum'],
                'finished_at' => $row['finished_at'] === null ? null : (string) $row['finished_at'],
                'backup_ref' => (string) $row['backup_ref'],
            ];
        }

        return $rows;
    }

    /** @param array<int, mixed> $applied */
    private function databaseIsEmpty(array $applied): bool
    {
        if ($applied !== []) {
            return false;
        }
        $count = Sql::scalar($this->pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME <> 'schema_migrations'");

        return (int) $count === 0;
    }

    private function ensureStateTable(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
              version SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
              name VARCHAR(100) NOT NULL,
              checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              backup_ref VARCHAR(160) NOT NULL,
              app_version VARCHAR(40) NOT NULL,
              started_at DATETIME(6) NOT NULL,
              finished_at DATETIME(6) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
            SQL);
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function lock(): void
    {
        if (Sql::int($this->pdo, 'SELECT GET_LOCK(?, 0)', [self::LOCK]) !== 1) {
            throw new MigrationError('Another migration run is in progress.');
        }
    }

    private function unlock(): void
    {
        Sql::run($this->pdo, 'SELECT RELEASE_LOCK(?)', [self::LOCK]);
    }
}
