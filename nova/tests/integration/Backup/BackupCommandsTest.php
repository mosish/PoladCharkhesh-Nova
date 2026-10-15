<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Backup;

use Nova\Cli\Application;
use Nova\Cli\Output;
use Nova\Core\Config;
use Nova\Core\Environment;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/** The operator commands, end to end, against the fixture's temporary folders and disposable databases. */
final class BackupCommandsTest extends TestCase
{
    private BackupFixture $b;
    private string $errorLog = '';

    protected function setUp(): void
    {
        $this->b = new BackupFixture();
        $this->errorLog = (string) ini_set('error_log', $this->b->tmp . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
        $this->b->dispose();
    }

    /**
     * @param list<string> $args
     * @param list<string> $secrets answers to successive secret prompts
     *
     * @return array{int, string, string} exit code, output, errors
     */
    private function cli(array $args, array $secrets = []): array
    {
        $out = new Output(false);
        $config = new Config(Environment::Development, TestDatabase::config(), ['paths' => ['public' => $this->b->tmp . '/public', 'storage' => $this->b->tmp . '/storage']]);
        $app = new Application($config, Paths::fromHere(), $out, 'cli', static function (string $_prompt) use (&$secrets): string {
            return array_shift($secrets) ?? '';
        });
        $code = $app->run(['nova', ...$args]);

        return [$code, implode("\n", $out->lines), implode("\n", $out->errors)];
    }

    public function testBackupRestoreAndVerifyCommandsEndToEnd(): void
    {
        $this->b->seed();
        [$code, $out] = $this->cli(['backup:run', '--trigger=scheduler']);
        self::assertSame(0, $code, $out);
        self::assertMatchesRegularExpression('/Backup (B-\d{8}-\d{6}-[0-9a-f]{4}) \(scheduler\): \d+ tables, schema 9/', $out);
        self::assertSame(1, preg_match('/Backup (B-\S+) /', $out, $m));
        $id = $m[1] ?? '';

        self::assertSame(0, $this->cli(['backup:verify', $id])[0]);
        self::assertStringContainsString($id, $this->cli(['backup:list'])[1]);
        [$statusCode, $status] = $this->cli(['backup:status']);
        self::assertSame(0, $statusCode);
        self::assertStringContainsString('Last successful backup', $status);

        $target = BackupFixture::target('A');
        [$code, $out] = $this->cli(['restore:db', $id, "--target={$target}"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('every table matches the backup', $out);
        self::assertSame(0, $this->cli(['restore:verify', $id, "--target={$target}"])[0]);

        // Refusals are explicit and exit non-zero.
        [$code, , $err] = $this->cli(['restore:db', $id, "--target={$target}"]);
        self::assertSame(0, $code, 'repeating the restore is idempotent');
        $b = BackupFixture::target('B');
        BackupFixture::connect($b)->exec('CREATE TABLE x (id INT)');
        [$code, , $err] = $this->cli(['restore:db', $id, "--target={$b}"]);
        self::assertSame([2, true], [$code, str_contains($err, '(not_empty)')]);
        [$code, , $err] = $this->cli(['restore:db', $id]);
        self::assertSame([2, true], [$code, str_contains($err, 'Usage: restore:db')]);
        [$code, , $err] = $this->cli(['restore:db', $id, '--target=' . TestDatabase::config()->name]);
        self::assertSame([2, true], [$code, str_contains($err, '(wrong_target)')]);

        // Media.
        $dir = $this->b->tmp . '/media-out';
        [$code, $out] = $this->cli(['restore:media', $id, "--target-dir={$dir}"]);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('5 copied', $out);
        [$code, , $err] = $this->cli(['restore:media', $id, '--target-dir=/']);
        self::assertSame([2, true], [$code, str_contains($err, '(wrong_target)')]);

        // A damaged set is reported and exits non-zero.
        file_put_contents($this->b->paths->dump($id), 'damaged');
        [$code, , $err] = $this->cli(['backup:verify', $id]);
        self::assertSame(2, $code);
        self::assertStringContainsString('checksum', $err);
    }

    public function testExportImportPruneArchiveAndTheConfigurationChecklist(): void
    {
        $this->b->seed();
        [, $out] = $this->cli(['backup:run']);
        self::assertSame(1, preg_match('/Backup (B-\S+) /', $out, $m));
        $id = $m[1] ?? '';
        $pass = 'a long enough passphrase';

        $file = $this->b->tmp . '/offsite.nova';
        [$code, $out] = $this->cli(['backup:export', $id, $file], [$pass, $pass]);
        self::assertSame(0, $code, $out);
        self::assertFileExists($file);
        [$code, , $err] = $this->cli(['backup:export', $id, $this->b->tmp . '/x.nova'], [$pass, 'different passphrase']);
        self::assertSame([2, true], [$code, str_contains($err, 'differ')]);
        [$code, , $err] = $this->cli(['backup:export', $id, $this->b->tmp . '/y.nova'], ['short', 'short']);
        self::assertSame([2, true], [$code, str_contains($err, '(passphrase)')]);

        // The passphrase is never an argument and never appears in the audit trail.
        $audit = implode(' ', array_map(strval(...), Sql::column($this->b->f->pdo, "SELECT COALESCE(after_text, '') FROM audit_event WHERE entity_type = 'backup'")));
        self::assertStringNotContainsString($pass, $audit);
        [$code, , $err] = $this->cli(['backup:import', $file], ['wrong passphrase entirely']);
        self::assertSame([2, true], [$code, str_contains($err, '(passphrase)')]);
        self::assertSame(0, $this->cli(['backup:import', $file], [$pass])[0]);

        [$code, $out] = $this->cli(['backup:archive', $id]);
        self::assertSame(0, $code);
        self::assertFileExists(trim(substr($out, \strlen('Archive: '))));
        self::assertSame(0, $this->cli(['backup:prune'])[0]);

        [$code, $out] = $this->cli(['backup:config-checklist']);
        self::assertSame(0, $code);
        self::assertStringContainsString('database.host', $out);
        self::assertStringContainsString('never holds', $out);
        self::assertStringNotContainsString(TestDatabaseSecrets::password(), $out, 'the checklist names keys and prints no values');
    }

    public function testOverdueIsReportedByTheStatusCommand(): void
    {
        [$code, $out] = $this->cli(['backup:status']);
        self::assertSame(3, $code);
        self::assertStringContainsString('No successful backup yet', $out);
        self::assertStringContainsString('OVERDUE', $out);
    }
}
