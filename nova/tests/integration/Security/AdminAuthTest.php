<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Security;

use Nova\Core\Sql;
use Nova\Security\AdminAuth;
use Nova\Security\ArraySessionStore;
use Nova\Security\Csrf;
use Nova\Security\Role;
use Nova\Security\Totp;
use Nova\Security\UserAdmin;
use Nova\Tests\Integration\Publish\TestClock;
use Nova\Tests\Integration\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Sign-in, TOTP, lockout, throttling and session lifetime (05 §14, E-10), against the disposable test database. */
final class AdminAuthTest extends TestCase
{
    private const string PASSWORD = 'a long test passphrase';
    private const string IP = '192.0.2.10';

    private PDO $pdo;
    private TestClock $clock;
    private ArraySessionStore $session;
    private AdminAuth $auth;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::migrated();
        $users = new UserAdmin($this->pdo, ['origin' => 'cli', 'os_user' => 'test']);
        $users->createFirstOwner('owner@test.invalid', 'مالک', self::PASSWORD);
        $users->createEditor('editor@test.invalid', 'ویرایشگر', self::PASSWORD);
        $this->clock = new TestClock(1_800_000_000);
        $this->session = new ArraySessionStore();
        $this->auth = new AdminAuth($this->pdo, $this->session, $this->clock);
    }

    /** @return list<string> */
    private function actions(): array
    {
        return array_map(strval(...), Sql::column($this->pdo, 'SELECT action FROM audit_event ORDER BY id'));
    }

    /** @return array<string, mixed> */
    private function lastAfter(string $action): array
    {
        $after = json_decode((string) Sql::scalar($this->pdo, 'SELECT after_text FROM audit_event WHERE action = ? ORDER BY id DESC LIMIT 1', [$action]), true);
        self::assertIsArray($after);

        /** @var array<string, mixed> */
        return $after;
    }

    private function enrolOwner(): string
    {
        self::assertSame('enroll', $this->auth->attemptPassword('owner@test.invalid', self::PASSWORD, self::IP));
        $secret = (string) $this->auth->enrollmentSecret();
        self::assertSame('ok', $this->auth->confirmEnrollment(Totp::code($secret, Totp::step($this->clock->now()))));
        $this->auth->signOut();

        return $secret;
    }

    public function testAnEditorSignsInWithAPasswordAndGetsAFreshSession(): void
    {
        $csrf = new Csrf($this->session);
        $before = $csrf->token();
        $id = $this->session->id;
        self::assertSame('ok', $this->auth->attemptPassword('EDITOR@test.invalid', self::PASSWORD, self::IP));
        $actor = $this->auth->current();
        self::assertNotNull($actor);
        self::assertSame(Role::Editor, $actor->role);
        self::assertNotSame($id, $this->session->id, 'the session id changed at sign-in');
        self::assertFalse($csrf->valid($before), 'the CSRF token changed at sign-in');
        self::assertContains('auth.sign_in', $this->actions());
        self::assertSame(['method' => 'password'], $this->lastAfter('auth.sign_in'));
    }

    public function testEveryFailureLooksTheSameAndIsAuditedWithItsRealReason(): void
    {
        self::assertSame('failed', $this->auth->attemptPassword('editor@test.invalid', 'wrong password!!', self::IP));
        self::assertSame('failed', $this->auth->attemptPassword('nobody@test.invalid', self::PASSWORD, self::IP));
        self::assertSame('failed', $this->auth->attemptPassword(self::PASSWORD, self::PASSWORD, self::IP));
        self::assertNull($this->auth->current());
        $rows = Sql::rows($this->pdo, "SELECT entity_label, after_text FROM audit_event WHERE action = 'auth.sign_in_failed' ORDER BY id");
        self::assertSame(['editor@test.invalid', 'nobody@test.invalid', '(not an email address)'], array_column($rows, 'entity_label'), 'a password typed into the email field is never logged');
        self::assertStringContainsString('bad_password', (string) $rows[0]['after_text']);
        self::assertStringContainsString('unknown_account', (string) $rows[1]['after_text']);
        self::assertStringNotContainsString(self::PASSWORD, implode(' ', array_map(strval(...), Sql::column($this->pdo, 'SELECT CONCAT_WS(" ", entity_label, before_text, after_text) FROM audit_event'))));
    }

    public function testTheAccountLocksAfterRepeatedFailuresWithBackOff(): void
    {
        for ($i = 0; $i < AdminAuth::LOCK_AFTER; ++$i) {
            self::assertSame('failed', $this->auth->attemptPassword('editor@test.invalid', 'wrong password!!', self::IP));
        }
        self::assertSame('failed', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, self::IP), 'locked: even the right password fails');
        self::assertSame(['reason' => 'locked'], $this->lastAfter('auth.sign_in_failed'));

        $this->clock->time += AdminAuth::LOCK_BASE + 1;
        self::assertSame('failed', $this->auth->attemptPassword('editor@test.invalid', 'wrong password!!', self::IP));
        $until = strtotime((string) Sql::scalar($this->pdo, "SELECT locked_until FROM app_user WHERE email = 'editor@test.invalid'") . ' UTC');
        self::assertSame($this->clock->time + 2 * AdminAuth::LOCK_BASE, $until, 'the next lock is twice as long');

        $this->clock->time = (int) $until + 1;
        self::assertSame('ok', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, self::IP));
        self::assertSame(0, Sql::int($this->pdo, "SELECT failed_logins FROM app_user WHERE email = 'editor@test.invalid'"), 'success clears the counter');
    }

    public function testOneAddressIsThrottledAcrossAccounts(): void
    {
        for ($i = 0; $i <= AdminAuth::IP_LIMIT; ++$i) {
            $this->auth->attemptPassword("user{$i}@test.invalid", 'wrong password!!', self::IP);
        }
        self::assertSame('failed', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, self::IP), 'blocked address');
        self::assertSame(['reason' => 'throttled'], $this->lastAfter('auth.sign_in_failed'));
        self::assertSame('ok', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, '198.51.100.7'), 'another address is not affected');
        self::assertSame(0, Sql::int($this->pdo, 'SELECT COUNT(*) FROM login_throttle WHERE subject_hash = ?', [self::IP]), 'the address itself is never stored');
        $this->clock->time += AdminAuth::IP_BLOCK + 1;
        self::assertSame('ok', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, self::IP));
    }

    public function testADisabledAccountCannotSignIn(): void
    {
        Sql::run($this->pdo, "UPDATE app_user SET status = 'disabled' WHERE email = 'editor@test.invalid'");
        self::assertSame('failed', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, self::IP));
        self::assertSame(['reason' => 'disabled'], $this->lastAfter('auth.sign_in_failed'));
    }

    public function testTheOwnerMustEnrolTotpBeforeAnythingElse(): void
    {
        self::assertSame('enroll', $this->auth->attemptPassword('owner@test.invalid', self::PASSWORD, self::IP));
        self::assertNull($this->auth->current(), 'a password alone signs nobody in as Owner');
        $secret = $this->auth->enrollmentSecret();
        self::assertNotNull($secret);
        self::assertSame($secret, $this->auth->enrollmentSecret(), 'one secret per pending sign-in');
        self::assertNull(Sql::scalar($this->pdo, "SELECT totp_secret FROM app_user WHERE email = 'owner@test.invalid'"), 'not stored until confirmed');

        self::assertSame('failed', $this->auth->confirmEnrollment('000000'));
        self::assertSame('ok', $this->auth->confirmEnrollment(Totp::code($secret, Totp::step($this->clock->now()))));
        $actor = $this->auth->current();
        self::assertNotNull($actor);
        self::assertTrue($actor->isOwner());
        self::assertSame($secret, Sql::scalar($this->pdo, "SELECT totp_secret FROM app_user WHERE email = 'owner@test.invalid'"));
        self::assertContains('auth.totp_enrolled', $this->actions());
        self::assertSame(['method' => 'password+totp'], $this->lastAfter('auth.sign_in'));
    }

    public function testAnEnrolledOwnerNeedsAFreshCodeEveryTime(): void
    {
        $secret = $this->enrolOwner();
        $this->clock->time += Totp::PERIOD;

        self::assertSame('totp', $this->auth->attemptPassword('owner@test.invalid', self::PASSWORD, self::IP));
        self::assertNull($this->auth->current());
        self::assertSame('failed', $this->auth->attemptTotp('123456' === Totp::code($secret, Totp::step($this->clock->now())) ? '654321' : '123456'));
        $code = Totp::code($secret, Totp::step($this->clock->now()));
        self::assertSame('ok', $this->auth->attemptTotp($code));
        self::assertNotNull($this->auth->current());
        $this->auth->signOut();

        self::assertSame('totp', $this->auth->attemptPassword('owner@test.invalid', self::PASSWORD, self::IP));
        self::assertSame('failed', $this->auth->attemptTotp($code), 'the same code is refused the second time (replay)');
    }

    public function testTotpGuessingLocksTheAccount(): void
    {
        $this->enrolOwner();
        $this->clock->time += Totp::PERIOD;
        self::assertSame('totp', $this->auth->attemptPassword('owner@test.invalid', self::PASSWORD, self::IP));
        $results = [];
        for ($i = 0; $i < AdminAuth::LOCK_AFTER; ++$i) {
            $results[] = $this->auth->attemptTotp('00000' . $i);
        }
        self::assertSame('expired', end($results), 'the pending sign-in ends when the account locks');
        self::assertSame('failed', $this->auth->attemptPassword('owner@test.invalid', self::PASSWORD, self::IP));
    }

    public function testALockThatBeginsAfterThePasswordStepStopsTheCodeStep(): void
    {
        $secret = $this->enrolOwner();
        $this->clock->time += Totp::PERIOD;
        self::assertSame('totp', $this->auth->attemptPassword('owner@test.invalid', self::PASSWORD, self::IP));
        // Meanwhile someone else exhausts the account's attempts.
        $elsewhere = new AdminAuth($this->pdo, new ArraySessionStore(), $this->clock);
        for ($i = 0; $i < AdminAuth::LOCK_AFTER; ++$i) {
            $elsewhere->attemptPassword('owner@test.invalid', 'wrong password!!', '203.0.113.9');
        }
        self::assertSame('expired', $this->auth->attemptTotp(Totp::code($secret, Totp::step($this->clock->now()))));
        self::assertNull($this->auth->current());
    }

    public function testAPendingSignInExpires(): void
    {
        $secret = $this->enrolOwner();
        self::assertSame('totp', $this->auth->attemptPassword('owner@test.invalid', self::PASSWORD, self::IP));
        $this->clock->time += AdminAuth::PENDING + 1;
        self::assertSame('expired', $this->auth->attemptTotp(Totp::code($secret, Totp::step($this->clock->now()))));
    }

    public function testSessionsEndOnIdleAbsoluteTimeoutAndCredentialChanges(): void
    {
        self::assertSame('ok', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, self::IP));
        $this->clock->time += AdminAuth::IDLE - 1;
        self::assertNotNull($this->auth->current(), 'activity keeps it alive');
        $this->clock->time += AdminAuth::IDLE + 1;
        self::assertNull($this->auth->current(), 'idle timeout');
        self::assertSame(['reason' => 'idle_timeout'], $this->lastAfter('auth.session_ended'));

        self::assertSame('ok', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, self::IP));
        for ($t = 0; $t <= AdminAuth::ABSOLUTE; $t += AdminAuth::IDLE - 60) {
            $this->clock->time += AdminAuth::IDLE - 60;
            $alive = $this->auth->current() !== null;
            if (!$alive) {
                break;
            }
        }
        self::assertSame(['reason' => 'absolute_timeout'], $this->lastAfter('auth.session_ended'));

        self::assertSame('ok', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, self::IP));
        (new UserAdmin($this->pdo, ['origin' => 'cli']))->resetPassword('editor@test.invalid', 'another long passphrase');
        self::assertNull($this->auth->current(), 'a password reset ends existing sessions');
        self::assertSame(['reason' => 'credentials_changed'], $this->lastAfter('auth.session_ended'));
    }

    public function testRoleAndStatusAreReadOnEveryRequest(): void
    {
        self::assertSame('ok', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, self::IP));
        Sql::run($this->pdo, "UPDATE app_user SET role = 'owner' WHERE email = 'editor@test.invalid'");
        self::assertSame(Role::Owner, $this->auth->current()?->role);
        Sql::run($this->pdo, "UPDATE app_user SET role = 'editor', status = 'disabled' WHERE email = 'editor@test.invalid'");
        self::assertNull($this->auth->current());
    }

    public function testATamperedSessionIsNotASession(): void
    {
        foreach ([['uid' => '1', 'epoch' => 1, 'at' => 1, 'seen' => 1], 'x', ['uid' => 999999, 'epoch' => 1, 'at' => $this->clock->time, 'seen' => $this->clock->time]] as $bad) {
            $this->session->set('auth', $bad);
            self::assertNull($this->auth->current());
        }
    }

    public function testAWeakHashIsUpgradedAtSignIn(): void
    {
        Sql::run($this->pdo, "UPDATE app_user SET password_hash = ? WHERE email = 'editor@test.invalid'", [password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])]);
        self::assertSame('ok', $this->auth->attemptPassword('editor@test.invalid', self::PASSWORD, self::IP));
        $hash = (string) Sql::scalar($this->pdo, "SELECT password_hash FROM app_user WHERE email = 'editor@test.invalid'");
        self::assertFalse(password_needs_rehash($hash, \defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT));
    }
}
