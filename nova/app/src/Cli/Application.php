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

    /** @var \Closure(string): string reads a secret (prompt => answer) without echoing it */
    private readonly \Closure $readSecret;

    /** @param (\Closure(string): string)|null $readSecret tests pass answers here; the default reads the terminal or standard input */
    public function __construct(
        private readonly Config $config,
        private readonly Paths $paths,
        private readonly Output $output = new Output(),
        private readonly string $sapi = PHP_SAPI,
        ?\Closure $readSecret = null,
    ) {
        $this->readSecret = $readSecret ?? self::terminalSecret(...);
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        // The operator command line never runs inside a web request (05 §14), whatever the entry point.
        if ($this->sapi !== 'cli') {
            $this->output->error('app/bin/nova runs only from the command line (PHP CLI).');

            return 2;
        }
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
                'user:create-owner' => $this->createUser($options, true),
                'user:create-editor' => $this->createUser($options, false),
                'user:reset-password' => $this->resetPassword($options),
                'user:reset-totp' => $this->resetTotp($options),
                'user:list' => $this->listUsers(),
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
        [$public] = \Nova\Publish\PublishFactory::locations($this->config, $this->paths);
        $manifest = \Nova\Publish\AssetBuilder::build($this->paths->root . '/assets-src', $public . '/assets');
        $admin = \Nova\Publish\AssetBuilder::buildAdmin($this->paths->root . '/assets-src', $manifest, $public . '/admin/assets');
        $this->output->line('Built ' . \count($manifest) . ' public assets into assets/ and ' . \count($admin) . ' Admin assets into admin/assets/.');

        return 0;
    }

    /** @param list<string> $options email, then the display name (the rest of the line) */
    private function createUser(array $options, bool $owner): int
    {
        $email = $options[0] ?? '';
        $name = trim(implode(' ', \array_slice($options, 1)));
        if ($email === '' || $name === '') {
            $this->output->error('Usage: app/bin/nova user:create-' . ($owner ? 'owner' : 'editor') . ' <email> <display name>');

            return 2;
        }
        $password = $this->newPassword();
        if ($password === null) {
            return 2;
        }
        $users = new \Nova\Security\UserAdmin($this->pdo(), \Nova\Publish\Initiator::currentProcess()->toArray());
        $owner ? $users->createFirstOwner($email, $name, $password) : $users->createEditor($email, $name, $password);
        $this->output->line(($owner ? 'Owner' : 'Editor') . " {$email} created." . ($owner ? ' Two-factor sign-in (TOTP) is set up at the first Admin sign-in.' : ''));

        return 0;
    }

    /** @param list<string> $options */
    private function resetPassword(array $options): int
    {
        $email = $options[0] ?? '';
        if ($email === '') {
            $this->output->error('Usage: app/bin/nova user:reset-password <email>');

            return 2;
        }
        $password = $this->newPassword();
        if ($password === null) {
            return 2;
        }
        (new \Nova\Security\UserAdmin($this->pdo(), \Nova\Publish\Initiator::currentProcess()->toArray()))->resetPassword($email, $password);
        $this->output->line("Password of {$email} reset; its sessions have ended and the lockout is cleared.");

        return 0;
    }

    /** @param list<string> $options */
    private function resetTotp(array $options): int
    {
        $email = $options[0] ?? '';
        if ($email === '') {
            $this->output->error('Usage: app/bin/nova user:reset-totp <email>');

            return 2;
        }
        (new \Nova\Security\UserAdmin($this->pdo(), \Nova\Publish\Initiator::currentProcess()->toArray()))->resetTotp($email);
        $this->output->line("Two-factor sign-in of {$email} reset; a new authenticator is enrolled at the next sign-in.");

        return 0;
    }

    private function listUsers(): int
    {
        foreach ((new \Nova\Security\UserAdmin($this->pdo(), []))->list() as $u) {
            $this->output->line(\sprintf('%-32s %-7s %-8s totp:%s%s  %s', $u['email'], $u['role'], $u['status'], $u['totp'] ? 'on ' : 'off', $u['locked'] ? ' LOCKED' : '', $u['display_name']));
        }

        return 0;
    }

    /** Asks twice; never echoes and never takes the password from the command line (history, process list). */
    private function newPassword(): ?string
    {
        $password = ($this->readSecret)('New password (at least ' . \Nova\Security\PasswordHasher::MIN_LENGTH . ' characters): ');
        $again = ($this->readSecret)('Repeat the password: ');
        if (!hash_equals($password, $again)) {
            $this->output->error('The two passwords differ; nothing was changed.');

            return null;
        }
        try {
            \Nova\Security\PasswordHasher::assertAcceptable($password);
        } catch (\InvalidArgumentException $e) {
            $this->output->error($e->getMessage());

            return null;
        }

        return $password;
    }

    private static function terminalSecret(string $prompt): string
    {
        fwrite(STDERR, $prompt);
        $tty = \function_exists('posix_isatty') && posix_isatty(STDIN);
        if ($tty) {
            shell_exec('stty -echo');
        }
        $line = fgets(STDIN);
        if ($tty) {
            shell_exec('stty echo');
            fwrite(STDERR, PHP_EOL);
        }

        return rtrim($line === false ? '' : $line, "\r\n");
    }

    /** Publish from the command line (the Admin's Publish button runs the same engine). */
    private function publish(): int
    {
        $result = \Nova\Publish\PublishFactory::engine($this->config, $this->paths, $this->pdo())->publish(\Nova\Publish\Initiator::currentProcess());
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
        $release = \Nova\Publish\PublishFactory::engine($this->config, $this->paths, $this->pdo())->rollback(\Nova\Publish\Initiator::currentProcess());
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
        $this->output->line('  user:create-owner <email> <name>   create the first Owner (once); the password is asked, never an argument');
        $this->output->line('  user:create-editor <email> <name>  create an Editor');
        $this->output->line('  user:reset-password <email>        set a new password (ends that account\'s sessions, clears a lockout)');
        $this->output->line('  user:reset-totp <email>            reset the Owner\'s two-factor sign-in (re-enrolled at the next sign-in)');
        $this->output->line('  user:list                          accounts, roles and status');

        return 0;
    }
}
