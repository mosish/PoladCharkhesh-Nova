<?php

declare(strict_types=1);

namespace Nova\Cli;

use Nova\Core\Config;
use Nova\Core\Database;
use Nova\Core\Environment;
use Nova\Core\Paths;
use Nova\Migration\BackupGuard;
use Nova\Migration\MigrationError;
use Nova\Migration\Migrator;
use Nova\Migration\UnavailableBackupGuard;
use PDO;
use Throwable;

/** `app/bin/nova <command>`: the operator command line (05 §4). */
final class Application
{
    public const string VERSION = '0.3.0-dev';

    private ?PDO $pdo = null;

    public function __construct(
        private readonly Config $config,
        private readonly Paths $paths,
        private readonly Output $output = new Output(),
        private readonly string $sapi = PHP_SAPI,
    ) {}

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = \array_slice($argv, 2);

        try {
            return match ($command) {
                'migrate' => $this->migrate($options),
                'migrate:status' => $this->migrateStatus(),
                'dev:sample-catalogue' => $this->sampleCatalogue(),
                default => $this->help(),
            };
        } catch (MigrationError $e) {
            $this->output->error('Migration refused: ' . $e->getMessage());

            return 2;
        } catch (Throwable $e) {
            $this->output->error($e::class . ': ' . $e->getMessage());

            return 1;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Database::connect($this->config->database);
    }

    /** @param list<string> $options */
    private function migrate(array $options): int
    {
        $guard = \in_array('--no-backup', $options, true) ? $this->noBackupGuard() : $this->backupGuard();
        $migrator = new Migrator($this->pdo(), $this->paths->migrations(), self::VERSION, $this->output->line(...));
        $applied = $migrator->migrate($guard);
        if ($applied === []) {
            $this->output->line('Nothing to migrate.');
        } elseif ($guard instanceof \Nova\Dev\NoBackupGuard) {
            $this->pdo()->prepare("INSERT INTO audit_event (action, entity_type, entity_label, after_text) VALUES ('migration.no_backup', 'schema', ?, ?)")
                ->execute([implode(', ', $applied), 'Development-only --no-backup bypass used.']);
        }

        return 0;
    }

    private function migrateStatus(): int
    {
        $migrator = new Migrator($this->pdo(), $this->paths->migrations(), self::VERSION);
        foreach ($migrator->status() as $row) {
            $this->output->line(\sprintf('%04d %-40s %s', $row['version'], $row['name'], $row['state']));
        }

        return 0;
    }

    private function sampleCatalogue(): int
    {
        if (!class_exists(\Nova\Dev\SampleCatalogue::class)) {
            $this->output->error('Sample fixtures are not available in this build.');

            return 2;
        }
        \Nova\Dev\SampleCatalogue::load($this->pdo(), $this->config->environment);
        $this->output->line('Loaded the Phase 2 SAMPLE catalogue (development fixture, is_sample = 1).');

        return 0;
    }

    /** The real pre-migration backup arrives with step 12; until then a non-empty database is refused. */
    private function backupGuard(): BackupGuard
    {
        return new UnavailableBackupGuard();
    }

    private function noBackupGuard(): BackupGuard
    {
        // Order matters: the environment is checked before the class is even looked up.
        if ($this->config->environment !== Environment::Development) {
            throw new MigrationError("--no-backup is refused: the environment is {$this->config->environment->value}, not development.");
        }
        if (!class_exists(\Nova\Dev\NoBackupGuard::class)) {
            throw new MigrationError('--no-backup is not available in this build.');
        }

        return new \Nova\Dev\NoBackupGuard($this->config->environment, $this->output->error(...), $this->sapi);
    }

    private function help(): int
    {
        $this->output->line('Usage: app/bin/nova <command>');
        $this->output->line('  migrate [--no-backup]   apply pending migrations (--no-backup: development only)');
        $this->output->line('  migrate:status          list migrations and their state');
        $this->output->line('  dev:sample-catalogue    load the Phase 2 SAMPLE fixtures (development only)');

        return 0;
    }
}
