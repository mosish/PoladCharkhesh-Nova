<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Security;

use Nova\Core\Sql;
use Nova\Security\AdminAuth;
use Nova\Security\ArraySessionStore;
use Nova\Security\Totp;
use Nova\Security\UserAdmin;
use Nova\Tests\Integration\Publish\TestClock;
use Nova\Tests\Integration\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Concurrent sign-in requests: two browsers (independent sessions) on two
 * independent database connections. The second request runs at the moment
 * the first has checked its credential and recorded nothing yet (the
 * AdminAuth test checkpoint), which is the race window, deterministically.
 * The last test adds real parallel processes for the counters.
 */
final class AdminAuthConcurrencyTest extends TestCase
{
    private const string PASSWORD = 'a long test passphrase';

    private PDO $a;
    private PDO $b;
    private TestClock $clock;

    protected function setUp(): void
    {
        $this->a = TestDatabase::migrated();
        $this->b = TestDatabase::connect();
        self::assertNotSame(Sql::int($this->a, 'SELECT CONNECTION_ID()'), Sql::int($this->b, 'SELECT CONNECTION_ID()'), 'two independent connections');
        $users = new UserAdmin($this->a, ['origin' => 'cli']);
        $users->createFirstOwner('owner@test.invalid', 'Owner', self::PASSWORD);
        $users->createEditor('editor@test.invalid', 'Editor', self::PASSWORD);
        $this->clock = new TestClock(1_800_000_000);
    }

    private function auth(PDO $pdo, ArraySessionStore $session, ?\Closure $checkpoint = null): AdminAuth
    {
        return new AdminAuth($pdo, $session, $this->clock, $checkpoint);
    }

    /** Runs `$other` once, the first time `$at` is reached. */
    private static function whenReached(string $at, \Closure $other): \Closure
    {
        $done = false;

        return static function (string $where) use ($at, $other, &$done): void {
            if (!$done && $where === $at) {
                $done = true;
                $other();
            }
        };
    }

    private function audits(string $action): int
    {
        return Sql::int($this->a, 'SELECT COUNT(*) FROM audit_event WHERE action = ?', [$action]);
    }

    private function enrolOwner(): string
    {
        $session = new ArraySessionStore();
        $auth = $this->auth($this->a, $session);
        self::assertSame('enroll', $auth->attemptPassword('owner@test.invalid', self::PASSWORD, '192.0.2.1'));
        $secret = (string) $auth->enrollmentSecret();
        self::assertSame('ok', $auth->confirmEnrollment(Totp::code($secret, Totp::step($this->clock->now()))));
        $this->clock->time += Totp::PERIOD;

        return $secret;
    }

    public function testOneTotpCodeSignsInOnlyOneOfTwoConcurrentSessions(): void
    {
        $secret = $this->enrolOwner();
        $sa = new ArraySessionStore();
        $sb = new ArraySessionStore();
        self::assertSame('totp', $this->auth($this->a, $sa)->attemptPassword('owner@test.invalid', self::PASSWORD, '192.0.2.1'));
        self::assertSame('totp', $this->auth($this->b, $sb)->attemptPassword('owner@test.invalid', self::PASSWORD, '192.0.2.2'));
        $code = Totp::code($secret, Totp::step($this->clock->now()));
        $signIns = $this->audits('auth.sign_in');

        $resultB = null;
        $resultA = $this->auth($this->a, $sa, self::whenReached('totp-checked', function () use ($sb, $code, &$resultB): void {
            $resultB = $this->auth($this->b, $sb)->attemptTotp($code);
        }))->attemptTotp($code);

        self::assertSame('ok', $resultB, 'the request that consumed the code first');
        self::assertSame('failed', $resultA, 'the same code, checked concurrently, is refused');
        self::assertNull($sa->get('auth'));
        self::assertIsArray($sb->get('auth'));
        self::assertSame($signIns + 1, $this->audits('auth.sign_in'));
        self::assertSame(Totp::step($this->clock->now()), Sql::int($this->a, 'SELECT totp_last_step FROM app_user WHERE role = ?', ['owner']));
    }

    public function testALosingConcurrentEnrolmentNeitherSignsInNorAudits(): void
    {
        $sa = new ArraySessionStore();
        $sb = new ArraySessionStore();
        $authA = $this->auth($this->a, $sa);
        $authB = $this->auth($this->b, $sb);
        self::assertSame('enroll', $authA->attemptPassword('owner@test.invalid', self::PASSWORD, '192.0.2.1'));
        self::assertSame('enroll', $authB->attemptPassword('owner@test.invalid', self::PASSWORD, '192.0.2.2'));
        $secretA = (string) $authA->enrollmentSecret();
        $secretB = (string) $authB->enrollmentSecret();
        self::assertNotSame($secretA, $secretB);

        $resultB = null;
        $resultA = $this->auth($this->a, $sa, self::whenReached('enrolment-checked', function () use ($authB, $secretB, &$resultB): void {
            $resultB = $authB->confirmEnrollment(Totp::code($secretB, Totp::step($this->clock->now())));
        }))->confirmEnrollment(Totp::code($secretA, Totp::step($this->clock->now())));

        self::assertSame('ok', $resultB);
        self::assertSame('expired', $resultA, 'the losing enrolment is not a sign-in');
        self::assertNull($sa->get('auth'));
        self::assertNull($sa->get('pending'));
        self::assertSame($secretB, Sql::scalar($this->a, "SELECT totp_secret FROM app_user WHERE role = 'owner'"), 'the winner\'s authenticator is the one stored');
        self::assertSame(1, $this->audits('auth.totp_enrolled'));
        self::assertSame(1, $this->audits('auth.sign_in'));
    }

    public function testConcurrentPasswordFailuresAreAllCounted(): void
    {
        $result = $this->auth($this->a, new ArraySessionStore(), self::whenReached('password-checked', function (): void {
            $this->auth($this->b, new ArraySessionStore())->attemptPassword('editor@test.invalid', 'wrong password!!', '192.0.2.2');
        }))->attemptPassword('editor@test.invalid', 'wrong password!!', '192.0.2.1');

        self::assertSame('failed', $result);
        self::assertSame(2, Sql::int($this->a, "SELECT failed_logins FROM app_user WHERE email = 'editor@test.invalid'"), 'no lost update');
    }

    public function testConcurrentTotpFailuresAreAllCounted(): void
    {
        $this->enrolOwner();
        $sa = new ArraySessionStore();
        $sb = new ArraySessionStore();
        self::assertSame('totp', $this->auth($this->a, $sa)->attemptPassword('owner@test.invalid', self::PASSWORD, '192.0.2.1'));
        self::assertSame('totp', $this->auth($this->b, $sb)->attemptPassword('owner@test.invalid', self::PASSWORD, '192.0.2.2'));

        $this->auth($this->a, $sa, self::whenReached('totp-checked', function () use ($sb): void {
            $this->auth($this->b, $sb)->attemptTotp('000000');
        }))->attemptTotp('111111');

        self::assertSame(2, Sql::int($this->a, "SELECT failed_logins FROM app_user WHERE role = 'owner'"));
    }

    public function testALockReachedWhileAPasswordIsCheckedStopsThatSignIn(): void
    {
        Sql::run($this->a, "UPDATE app_user SET failed_logins = ? WHERE email = 'editor@test.invalid'", [AdminAuth::LOCK_AFTER - 1]);
        $session = new ArraySessionStore();
        $result = $this->auth($this->a, $session, self::whenReached('password-checked', function (): void {
            // The failure that reaches the limit lands while the right password is being checked.
            $this->auth($this->b, new ArraySessionStore())->attemptPassword('editor@test.invalid', 'wrong password!!', '192.0.2.2');
        }))->attemptPassword('editor@test.invalid', self::PASSWORD, '192.0.2.1');

        self::assertSame('failed', $result);
        self::assertNull($session->get('auth'));
        $row = Sql::row($this->a, "SELECT failed_logins, locked_until FROM app_user WHERE email = 'editor@test.invalid'");
        self::assertNotNull($row);
        self::assertSame(AdminAuth::LOCK_AFTER, (int) $row['failed_logins'], 'the lock is not cleared by the concurrent success');
        self::assertNotNull($row['locked_until']);
        self::assertSame(0, $this->audits('auth.sign_in'));
    }

    public function testALockReachedWhileACodeIsCheckedStopsThatSignIn(): void
    {
        $secret = $this->enrolOwner();
        Sql::run($this->a, "UPDATE app_user SET failed_logins = ? WHERE role = 'owner'", [AdminAuth::LOCK_AFTER - 1]);
        $sa = new ArraySessionStore();
        self::assertSame('totp', $this->auth($this->a, $sa)->attemptPassword('owner@test.invalid', self::PASSWORD, '192.0.2.1'));
        $signIns = $this->audits('auth.sign_in');

        $result = $this->auth($this->a, $sa, self::whenReached('totp-checked', function (): void {
            $this->auth($this->b, new ArraySessionStore())->attemptPassword('owner@test.invalid', 'wrong password!!', '192.0.2.2');
        }))->attemptTotp(Totp::code($secret, Totp::step($this->clock->now())));

        self::assertSame('expired', $result);
        self::assertNull($sa->get('auth'));
        self::assertSame($signIns, $this->audits('auth.sign_in'));
        self::assertNull(Sql::scalar($this->a, "SELECT totp_last_step FROM app_user WHERE role = 'owner' AND totp_last_step = ?", [Totp::step($this->clock->now())]), 'the code was not spent');
    }

    /**
     * Real parallel processes, each with its own connection: every failure is
     * counted exactly once, per address and per account.
     */
    public function testParallelProcessesNeverLoseAFailure(): void
    {
        $worker = __DIR__ . '/concurrent-attempts.php';
        $start = microtime(true) + 1.5;
        $processes = [];
        $spawn = static function (string ...$args) use ($worker, $start, &$processes): void {
            $p = proc_open([PHP_BINARY, $worker, (string) $start, ...array_values($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($p);
            $processes[] = [$p, $pipes];
        };
        // Unknown accounts from one address: the address counter alone.
        for ($i = 0; $i < 6; ++$i) {
            $spawn("nobody{$i}@test.invalid", 'wrong password!!', '4', '198.51.100.1');
        }
        // One account from several addresses: the account counter.
        for ($i = 0; $i < 6; ++$i) {
            $spawn('editor@test.invalid', 'wrong password!!', '2', "203.0.113.{$i}");
        }
        foreach ($processes as [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($p), (string) $out);
        }

        self::assertSame(24, Sql::int($this->a, 'SELECT failures FROM login_throttle WHERE subject_hash = ?', [hash('sha256', '198.51.100.1')]), 'every failure from the address counted');
        $editor = (int) Sql::scalar($this->a, "SELECT id FROM app_user WHERE email = 'editor@test.invalid'");
        $counted = Sql::int($this->a, 'SELECT failed_logins FROM app_user WHERE id = ?', [$editor]);
        $audited = Sql::int($this->a, "SELECT COUNT(*) FROM audit_event WHERE action = 'auth.sign_in_failed' AND entity_id = ? AND after_text LIKE '%bad_password%'", [$editor]);
        self::assertSame($audited, $counted, 'each audited wrong password is counted once');
        self::assertGreaterThanOrEqual(AdminAuth::LOCK_AFTER, $counted);
        self::assertNotNull(Sql::scalar($this->a, 'SELECT locked_until FROM app_user WHERE id = ?', [$editor]));
    }
}
