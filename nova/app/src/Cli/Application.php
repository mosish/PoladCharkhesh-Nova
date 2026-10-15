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
                'inquiry:notify' => $this->inquiryNotify(),
                'inquiry:purge' => $this->inquiryPurge(),
                'media:purge' => $this->mediaPurge(),
                'backup:run' => $this->backupRun($options),
                'backup:list' => $this->backupList(),
                'backup:status' => $this->backupStatus(),
                'backup:verify' => $this->backupVerify($options),
                'backup:prune' => $this->backupPrune(),
                'backup:archive' => $this->backupArchive($options),
                'backup:export' => $this->backupExport($options),
                'backup:import' => $this->backupImport($options),
                'backup:config-checklist' => $this->backupChecklist(),
                'restore:db' => $this->restoreDb($options),
                'restore:verify' => $this->restoreVerify($options),
                'restore:cleanup' => $this->restoreCleanup($options),
                'restore:media' => $this->restoreMedia($options),
                default => $this->help(),
            };
        } catch (\Nova\Backup\BackupError $e) {
            $this->output->error("Refused or failed ({$e->reason}): " . $e->getMessage());

            return 2;
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

    /** Retries every pending or failed inquiry notification (05 §13); the scheduler of step 12 runs it too. */
    private function inquiryNotify(): int
    {
        $settings = \Nova\Inquiry\InquiryConfig::fromConfig($this->config);
        $build = \Nova\Publish\PublishFactory::buildConfig($this->config);
        $notifier = new \Nova\Inquiry\InquiryNotifier($this->pdo(), new \Nova\Inquiry\SmtpMailer($settings->smtp, $settings->from), $build->hosts['fa']);
        $result = $notifier->retryAll();
        $this->output->line("Notifications sent: {$result['sent']}, failed: {$result['failed']}.");
        foreach (\Nova\Core\Sql::rows($this->pdo(), "SELECT id, notify_error FROM inquiry WHERE notify_status = 'failed' ORDER BY id LIMIT 20") as $row) {
            $this->output->line(\sprintf('  IQ-%d  %s', (int) $row['id'], (string) $row['notify_error']));
        }

        return $result['failed'] === 0 ? 0 : 3;
    }

    /** Permanently removes media and documents trashed for 30 days, then files no record names (05 §12). */
    private function mediaPurge(): int
    {
        [$public] = \Nova\Publish\PublishFactory::locations($this->config, $this->paths);
        $audit = new \Nova\Audit\AuditLog($this->pdo());
        $storage = new \Nova\Media\MediaStorage($public);
        $limits = \Nova\Media\MediaLimits::fromConfig($this->config);
        $clock = new \Nova\Publish\SystemClock();
        $documents = (new \Nova\Media\DocumentService($this->pdo(), $storage, $limits, $clock, $audit))->purgeTrash();
        $switcher = \Nova\Publish\PublishFactory::switcher($this->config, $this->paths);
        $pdo = $this->pdo();
        $retained = static fn(): array => \Nova\Media\ReleaseMedia::referenced($pdo, $switcher);
        $media = (new \Nova\Media\MediaService($this->pdo(), $storage, $limits, $clock, $audit, $retained))->purgeTrash();
        $this->output->line("Purged {$media['items']} images, {$documents} documents and {$media['files']} unreferenced files.");

        return 0;
    }

    /** Retention (E-12): deletes inquiries older than 24 months and address hashes older than 24 hours. */
    private function inquiryPurge(): int
    {
        $retention = new \Nova\Inquiry\InquiryRetention($this->pdo());
        $total = 0;
        while (($n = $retention->purgeExpired()) > 0) {
            $total += $n;
        }
        $settings = \Nova\Inquiry\InquiryConfig::fromConfig($this->config);
        $throttle = new \Nova\Inquiry\Throttle($this->pdo(), $settings->key, $settings->perHour, $settings->perDay);
        $hashes = 0;
        while (($n = $throttle->purge()) > 0) {
            $hashes += $n;
        }
        $this->output->line("Deleted {$total} inquiries past " . \Nova\Inquiry\InquiryRetention::MONTHS . " months and {$hashes} address hashes past 24 hours.");

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

    /** A non-empty database is backed up (verified) before the first migration; a failed backup refuses the migration. */
    private function backupGuard(): BackupGuard
    {
        return new \Nova\Backup\PreMigrationBackupGuard($this->backups());
    }

    private ?\Nova\Backup\BackupService $backupService = null;

    private function backups(): \Nova\Backup\BackupService
    {
        return $this->backupService ??= \Nova\Backup\BackupFactory::service($this->config, $this->paths, $this->pdo(), self::VERSION);
    }

    private function restores(): \Nova\Backup\RestoreService
    {
        return \Nova\Backup\BackupFactory::restore($this->config, $this->paths, $this->pdo(), $this->backups());
    }

    /**
     * `--name=value` from the command-line options.
     *
     * @param list<string> $options
     */
    private static function option(array $options, string $name): ?string
    {
        foreach ($options as $o) {
            if (str_starts_with($o, "--{$name}=")) {
                return substr($o, \strlen($name) + 3);
            }
        }

        return null;
    }

    /**
     * The first argument that is not an option.
     *
     * @param list<string> $options
     */
    private static function argument(array $options, int $n = 0): ?string
    {
        $args = array_values(array_filter($options, static fn(string $o): bool => !str_starts_with($o, '--')));

        return $args[$n] ?? null;
    }

    /** @param list<string> $options */
    private function backupRun(array $options): int
    {
        $trigger = self::option($options, 'trigger') ?? 'manual';
        if (!\in_array($trigger, ['scheduler', 'fallback', 'manual'], true)) {
            $this->output->error('--trigger is scheduler, fallback or manual.');

            return 2;
        }
        $r = $this->backups()->run($trigger, 'cli', !\in_array('--no-prune', $options, true));
        $this->output->line("Backup {$r['id']} ({$trigger}): {$r['tables']} tables, schema {$r['schema_version']}, database {$r['db_bytes']} bytes, {$r['media_files']} media files ({$r['media_copied']} new).");
        foreach ($r['missing'] as $m) {
            $this->output->line("warning: {$m} is named by the database but was not on disk");
        }

        return 0;
    }

    private function backupList(): int
    {
        foreach ($this->backups()->sets() as $s) {
            $this->output->line(\sprintf('%s  %-13s %s%s', $s['id'], $s['trigger'], gmdate('Y-m-d H:i', $s['at']) . ' UTC', $s['ok'] ? '' : '  BROKEN: ' . $s['problem']));
        }

        return 0;
    }

    private function backupStatus(): int
    {
        $st = $this->backups()->status();
        $this->output->line($st['last'] === null ? 'No successful backup yet.' : "Last successful backup: {$st['last']['id']} ({$st['last']['trigger']}), {$st['hours_since']} h ago.");
        if ($st['overdue']) {
            $this->output->line('OVERDUE: run backup:run (cron), or switch on backup.fallback.');
        }
        if ($st['last_failure'] !== null) {
            $this->output->line("The last backup attempt failed at {$st['last_failure']} UTC (see the audit trail).");
        }

        return $st['overdue'] || $st['last_failure'] !== null ? 3 : 0;
    }

    /** @param list<string> $options */
    private function backupVerify(array $options): int
    {
        $id = self::argument($options) ?? '';
        $problems = $this->backups()->verify($id);
        foreach ($problems as $p) {
            $this->output->error($p);
        }
        $this->output->line($problems === [] ? "{$id} is whole and restorable." : "{$id} is NOT restorable.");

        return $problems === [] ? 0 : 2;
    }

    private function backupPrune(): int
    {
        $deleted = $this->backups()->prune();
        $this->output->line($deleted === [] ? 'Nothing to prune.' : 'Pruned: ' . implode(', ', $deleted));

        return 0;
    }

    /** @param list<string> $options */
    private function backupArchive(array $options): int
    {
        $this->output->line('Archive: ' . $this->backups()->archive(self::argument($options) ?? ''));

        return 0;
    }

    /** @param list<string> $options */
    private function backupExport(array $options): int
    {
        $id = self::argument($options) ?? '';
        $out = self::argument($options, 1);
        if ($out === null) {
            $this->output->error('Usage: backup:export <set-id> <output-file>');

            return 2;
        }
        $pass = ($this->readSecret)('Passphrase for the off-site copy (at least ' . \Nova\Backup\OffsiteCrypto::MIN_PASSPHRASE . ' characters): ');
        if ($pass !== ($this->readSecret)('Repeat the passphrase: ')) {
            $this->output->error('The passphrases differ.');

            return 2;
        }
        (new \Nova\Backup\OffsiteBundle(\Nova\Backup\BackupFactory::paths($this->config, $this->paths), $this->backups()))->export($id, $out, $pass);
        $this->pdoAudit('backup.export', $id, ['origin' => 'cli']);
        $this->output->line("Encrypted copy written to {$out}. Keep the passphrase safe: it cannot be recovered.");

        return 0;
    }

    /** @param list<string> $options */
    private function backupImport(array $options): int
    {
        $in = self::argument($options);
        if ($in === null) {
            $this->output->error('Usage: backup:import <encrypted-file>');

            return 2;
        }
        $pass = ($this->readSecret)('Passphrase: ');
        $id = (new \Nova\Backup\OffsiteBundle(\Nova\Backup\BackupFactory::paths($this->config, $this->paths), $this->backups()))->import($in, $pass);
        $this->pdoAudit('backup.import', $id, ['origin' => 'cli']);
        $this->output->line("Imported and verified set {$id}.");

        return 0;
    }

    /** @param array<string, mixed> $after */
    private function pdoAudit(string $action, string $label, array $after): void
    {
        try {
            (new \Nova\Audit\AuditLog($this->pdo()))->record(null, $action, 'backup', null, $label, null, $after);
        } catch (Throwable) {
            // A new host's database has no audit table until a restore has made it; the command itself already succeeded.
        }
    }

    private function backupChecklist(): int
    {
        $this->output->line('Configuration is never in a backup. To rebuild config.php on a new host you need values for (names only):');
        foreach (\Nova\Backup\ConfigChecklist::keys() as $k) {
            $this->output->line(\sprintf('  %-72s %-20s %s', $k['key'], $k['needed'], $k['note']));
        }
        /** @var array{included: list<string>, excluded: list<string>} $b */
        $b = \Nova\Backup\ConfigChecklist::boundaries();
        $this->output->line('A backup holds:');
        foreach ($b['included'] as $i) {
            $this->output->line("  + {$i}");
        }
        $this->output->line('A backup never holds:');
        foreach ($b['excluded'] as $i) {
            $this->output->line("  - {$i}");
        }

        return 0;
    }

    /** @param list<string> $options */
    private function restoreDb(array $options): int
    {
        $id = self::argument($options) ?? '';
        $target = self::option($options, 'target');
        if ($target === null) {
            $this->output->error('Usage: restore:db <set-id> --target=<empty database> [--replace-live --confirm=<live database>]');

            return 2;
        }
        $r = $this->restores()->restoreDatabase($id, $target, \in_array('--replace-live', $options, true), self::option($options, 'confirm'));
        $this->output->line("{$r['status']}: {$r['tables']} tables, {$r['rows']} rows, schema {$r['schema_version']} into {$target}; every table matches the backup.");

        return 0;
    }

    /** @param list<string> $options */
    private function restoreVerify(array $options): int
    {
        $target = self::option($options, 'target');
        if ($target === null) {
            $this->output->error('Usage: restore:verify <set-id> --target=<database>');

            return 2;
        }
        $problems = $this->restores()->verifyTarget(self::argument($options) ?? '', $target);
        foreach ($problems as $p) {
            $this->output->error($p);
        }
        $this->output->line($problems === [] ? "{$target} matches the backup." : "{$target} DIFFERS from the backup.");

        return $problems === [] ? 0 : 2;
    }

    /** @param list<string> $options */
    private function restoreCleanup(array $options): int
    {
        $target = self::option($options, 'target');
        if ($target === null) {
            $this->output->error('Usage: restore:cleanup --target=<database with an interrupted restore>');

            return 2;
        }
        $n = $this->restores()->cleanup($target, \in_array('--replace-live', $options, true), self::option($options, 'confirm'));
        $this->output->line("Emptied {$target}: {$n} tables removed.");

        return 0;
    }

    /** @param list<string> $options */
    private function restoreMedia(array $options): int
    {
        $dir = self::option($options, 'target-dir');
        if ($dir === null) {
            $this->output->error('Usage: restore:media <set-id> --target-dir=<media folder> [--archive=<zip>]');

            return 2;
        }
        $r = $this->restores()->restoreMedia(self::argument($options) ?? '', $dir, self::option($options, 'archive'));
        $this->output->line("Media restored into {$dir}: {$r['restored']} copied, {$r['unchanged']} already there; {$r['missing_at_backup']} were missing when the backup was made.");

        return 0;
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
        $this->output->line('  inquiry:notify          retry pending or failed inquiry notifications');
        $this->output->line('  backup:run [--trigger=scheduler|manual|fallback] [--no-prune]   database + media backup with retention (cron: daily)');
        $this->output->line('  backup:list | backup:status | backup:verify <id> | backup:prune | backup:archive <id>');
        $this->output->line('  backup:export <id> <file> | backup:import <file>   encrypted off-site copy (passphrase asked)');
        $this->output->line('  backup:config-checklist  what a backup holds and the configuration names to keep elsewhere');
        $this->output->line('  restore:db <id> --target=<empty db> [--replace-live --confirm=<live db>] | restore:verify <id> --target=<db> | restore:cleanup --target=<db>');
        $this->output->line('  restore:media <id> --target-dir=<folder> [--archive=<zip>]');
        $this->output->line('  media:purge             delete media and documents trashed for 30 days and unreferenced files');
        $this->output->line('  inquiry:purge           delete inquiries past retention (24 months) and old address hashes');

        return 0;
    }
}
