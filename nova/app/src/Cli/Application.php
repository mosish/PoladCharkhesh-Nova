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
                'dev:reset' => $this->devReset(),
                'assets:build' => $this->assetsBuild(),
                'publish' => $this->publish(),
                'publish:rollback' => $this->publishRollback(),
                'publish:recover' => $this->publishRecover(),
                'publish:status' => $this->publishStatus(),
                'dev:e2e' => $this->devE2e(),
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

    /**
     * DEVELOPMENT ONLY: empties the local development database, migrates it and
     * loads the sample fixtures. Refused in any other environment.
     */
    private function devReset(): int
    {
        if ($this->config->environment !== Environment::Development || $this->sapi !== 'cli' || !class_exists(\Nova\Dev\SampleCatalogue::class)) {
            $this->output->error('dev:reset is available only from the command line of a development build.');

            return 2;
        }
        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (\Nova\Core\Sql::column($pdo, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $table) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string) $table) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->output->line('Development database emptied.');
        (new Migrator($pdo, $this->paths->migrations(), self::VERSION, $this->output->line(...)))->migrate(new UnavailableBackupGuard());

        return $this->sampleCatalogue();
    }

    private function assetsBuild(): int
    {
        $manifest = \Nova\Publish\AssetBuilder::build($this->paths->root . '/assets-src', $this->paths->root . '/public_html/assets');
        $this->output->line('Built ' . \count($manifest) . ' assets into public_html/assets/.');

        return 0;
    }

    /** Publish from the command line (the Admin's Publish button runs the same engine). */
    private function publish(): int
    {
        $result = \Nova\Publish\PublishFactory::engine($this->config, $this->paths, $this->pdo())->publish(null);
        foreach ($result->warnings as $w) {
            $this->output->line("warning: {$w}");
        }
        if ($result->status === 'waiting') {
            $this->output->error("The inactive slot can be reused in {$result->waitSeconds} s; publish again then.");

            return 3;
        }
        if ($result->status === 'failed') {
            foreach ($result->blockers as $b) {
                $this->output->error("blocker: {$b}");
            }

            return 2;
        }
        $this->output->line("Published {$result->release}.");

        return 0;
    }

    private function publishRollback(): int
    {
        $release = \Nova\Publish\PublishFactory::engine($this->config, $this->paths, $this->pdo())->rollback(null);
        $this->output->line("Rolled back: {$release} is live.");

        return 0;
    }

    private function publishRecover(): int
    {
        $message = \Nova\Publish\PublishFactory::engine($this->config, $this->paths, $this->pdo())->recover();
        $this->output->line($message ?? 'No interrupted publish.');

        return 0;
    }

    private function publishStatus(): int
    {
        foreach (\Nova\Core\Sql::rows($this->pdo(), 'SELECT release_id, status, strategy, went_live_at FROM publish_record ORDER BY id DESC LIMIT 10') as $row) {
            $this->output->line(\sprintf('%s  %-11s %-7s %s', (string) $row['release_id'], (string) $row['status'], (string) $row['strategy'], (string) ($row['went_live_at'] ?? '')));
        }

        return 0;
    }

    /** DEVELOPMENT ONLY: the browser-test site: dev:reset + synthetic QZ fixtures + assets + publish. */
    private function devE2e(): int
    {
        $code = $this->devReset();
        if ($code !== 0 || !class_exists(\Nova\Dev\SyntheticCatalogue::class)) {
            return $code === 0 ? 2 : $code;
        }
        \Nova\Dev\SyntheticCatalogue::load($this->pdo(), $this->config->environment);
        $this->output->line('Added the SYNTHETIC browser-test designations (QZ…).');
        $this->assetsBuild();

        return $this->publish();
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
        $this->output->line('  dev:reset               rebuild the local development database with the SAMPLE fixtures (development only)');
        $this->output->line('  assets:build            build content-hashed assets into public_html/assets/');
        $this->output->line('  publish                 validate, build and switch a new release (rollback: publish:rollback)');
        $this->output->line('  publish:recover         finish or roll back an interrupted publish');
        $this->output->line('  publish:status          the last releases and their state');
        $this->output->line('  dev:e2e                 dev DB = SAMPLE + SYNTHETIC fixtures, then assets + publish (development only)');

        return 0;
    }
}
