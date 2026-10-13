<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Cli;

use Nova\Cli\Application;
use Nova\Cli\Output;
use Nova\Core\Config;
use Nova\Core\Environment;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Security\PasswordHasher;
use Nova\Tests\Integration\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** First Owner and command-line recovery (05 §14): no default accounts, passwords never on the command line. */
final class UserCommandsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::migrated();
    }

    /**
     * @param list<string> $argv
     * @param list<string> $answers what the operator types at the hidden prompts
     */
    private function nova(array $argv, array $answers, ?Output $output = null): int
    {
        $output ??= new Output(false);
        $app = new Application(new Config(Environment::Production, TestDatabase::config()), Paths::fromHere(), $output, 'cli', static function () use (&$answers): string {
            return (string) array_shift($answers);
        });

        return $app->run(['nova', ...$argv]);
    }

    public function testThereIsNoAccountUntilTheOperatorCreatesOne(): void
    {
        self::assertSame(0, Sql::int($this->pdo, 'SELECT COUNT(*) FROM app_user'), 'migrations create no default account');
    }

    public function testTheFirstOwnerIsCreatedOnceWithAHashedPassword(): void
    {
        $out = new Output(false);
        self::assertSame(0, $this->nova(['user:create-owner', 'owner@example.test', 'Mosi', 'Owner'], ['a long owner passphrase', 'a long owner passphrase'], $out));
        $row = Sql::row($this->pdo, 'SELECT role, display_name, password_hash, totp_enabled_at FROM app_user WHERE email = ?', ['owner@example.test']);
        self::assertNotNull($row);
        self::assertSame(['owner', 'Mosi Owner', null], [$row['role'], $row['display_name'], $row['totp_enabled_at']]);
        self::assertTrue(PasswordHasher::verify('a long owner passphrase', (string) $row['password_hash']));
        self::assertStringNotContainsString('a long owner passphrase', implode("\n", [...$out->lines, ...$out->errors]));
        $audit = json_decode((string) Sql::scalar($this->pdo, "SELECT after_text FROM audit_event WHERE action = 'user.create'"), true);
        self::assertIsArray($audit);
        self::assertIsArray($audit['by']);
        self::assertSame('cli', $audit['by']['origin'] ?? null);

        $again = new Output(false);
        self::assertSame(1, $this->nova(['user:create-owner', 'second@example.test', 'Second'], ['another long passphrase', 'another long passphrase'], $again));
        self::assertStringContainsString('already exists', implode("\n", $again->errors));
        self::assertSame(1, Sql::int($this->pdo, "SELECT COUNT(*) FROM app_user WHERE role = 'owner'"));
    }

    public function testBadInputChangesNothing(): void
    {
        $cases = [
            [['user:create-owner', 'owner@example.test', 'Mosi'], ['a long owner passphrase', 'a different passphrase']],
            [['user:create-owner', 'owner@example.test', 'Mosi'], ['short', 'short']],
            [['user:create-owner', 'not-an-email', 'Mosi'], ['a long owner passphrase', 'a long owner passphrase']],
            [['user:create-owner', 'owner@example.test'], []],
            [['user:create-editor', 'editor@example.test', 'E'], ['a long editor passphrase', 'a long editor passphrase']],
        ];
        foreach ($cases as [$argv, $answers]) {
            self::assertNotSame(0, $this->nova($argv, $answers), implode(' ', $argv));
        }
        self::assertSame(0, Sql::int($this->pdo, 'SELECT COUNT(*) FROM app_user'));
    }

    public function testRecoveryResetsPasswordAndTotpAndEndsSessions(): void
    {
        $this->nova(['user:create-owner', 'owner@example.test', 'Mosi'], ['a long owner passphrase', 'a long owner passphrase']);
        Sql::run($this->pdo, "UPDATE app_user SET totp_secret = 'JBSWY3DPEHPK3PXP', totp_enabled_at = UTC_TIMESTAMP(), failed_logins = 9, locked_until = '2099-01-01 00:00:00'");
        $epoch = Sql::int($this->pdo, 'SELECT session_epoch FROM app_user');

        self::assertSame(0, $this->nova(['user:reset-password', 'owner@example.test'], ['a new long passphrase', 'a new long passphrase']));
        $row = Sql::row($this->pdo, 'SELECT password_hash, failed_logins, locked_until, session_epoch FROM app_user');
        self::assertNotNull($row);
        self::assertTrue(PasswordHasher::verify('a new long passphrase', (string) $row['password_hash']));
        self::assertSame([0, null, $epoch + 1], [(int) $row['failed_logins'], $row['locked_until'], (int) $row['session_epoch']]);

        self::assertSame(0, $this->nova(['user:reset-totp', 'owner@example.test'], []));
        self::assertSame([null, null], array_values(Sql::row($this->pdo, 'SELECT totp_secret, totp_enabled_at FROM app_user') ?? []));
        self::assertSame($epoch + 2, Sql::int($this->pdo, 'SELECT session_epoch FROM app_user'));
        self::assertSame(['user.create', 'user.password_reset', 'user.totp_reset'], array_map(strval(...), Sql::column($this->pdo, 'SELECT action FROM audit_event ORDER BY id')));

        $list = new Output(false);
        self::assertSame(0, $this->nova(['user:list'], [], $list));
        self::assertStringContainsString('owner@example.test', $list->lines[0] ?? '');
        self::assertSame(1, $this->nova(['user:reset-totp', 'nobody@example.test'], []));
    }
}
