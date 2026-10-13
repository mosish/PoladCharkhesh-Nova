<?php

declare(strict_types=1);

namespace Nova\Security;

use Nova\Audit\AuditLog;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use Nova\Publish\Clock;
use Nova\Publish\SystemClock;
use PDO;

/**
 * Admin sign-in and the signed-in session (05 §14, E-10).
 *
 * - One generic failure for every reason (unknown account, wrong password,
 *   disabled, locked, throttled); the audit trail records the real reason.
 *   An unknown account costs the same password work as a known one.
 * - Per account: after LOCK_AFTER consecutive failures (password or TOTP)
 *   the account locks, for LOCK_BASE seconds doubling per further failure
 *   up to LOCK_MAX. Per client address (stored only as a SHA-256): more than
 *   IP_LIMIT failures within IP_WINDOW blocks that address for IP_BLOCK.
 * - The Owner always needs a TOTP code; an Owner without one enrols before
 *   anything else. A code is accepted once (its time step is recorded).
 * - A new session id at the password step and again when fully signed in.
 *   Sessions end after IDLE seconds without a request, ABSOLUTE seconds after
 *   sign-in, or when the account's session_epoch changes (password or TOTP
 *   reset, account disabled). Role and status are re-read on every request.
 *
 * These limits are ordinary implementation parameters, not product data.
 */
final readonly class AdminAuth
{
    public const int IDLE = 1800;
    public const int ABSOLUTE = 43200;
    public const int PENDING = 300;
    public const int LOCK_AFTER = 5;
    public const int LOCK_BASE = 60;
    public const int LOCK_MAX = 3600;
    public const int IP_LIMIT = 20;
    public const int IP_WINDOW = 900;
    public const int IP_BLOCK = 900;
    public const string ISSUER = 'Polad Charkhesh Nova';

    private AuditLog $audit;

    /**
     * @param (\Closure(string): void)|null $checkpoint tests only: called at 'password-checked', 'totp-checked' and
     *                                                  'enrolment-checked' (a credential has been checked, nothing recorded yet),
     *                                                  so a concurrent request can be interleaved deterministically
     */
    public function __construct(
        private PDO $pdo,
        private SessionStore $session,
        private Clock $clock = new SystemClock(),
        private ?\Closure $checkpoint = null,
    ) {
        $this->audit = new AuditLog($pdo);
    }

    /** Step 1: email and password. Returns 'ok', 'totp', 'enroll' or 'failed'. */
    public function attemptPassword(string $email, string $password, string $clientAddress): string
    {
        $this->session->remove('pending');
        $email = trim($email);
        $now = $this->clock->now();
        $addressKey = hash('sha256', $clientAddress);
        $user = mb_strlen($email, 'UTF-8') <= 254 ? Sql::row($this->pdo, 'SELECT * FROM app_user WHERE email = ?', [$email]) : null;

        if ($this->addressBlocked($addressKey, $now)) {
            PasswordHasher::verifyDummy($password);
            $this->failed($user, $email, 'throttled', $addressKey, $now, countAgainstAccount: false);

            return 'failed';
        }
        if ($user === null) {
            PasswordHasher::verifyDummy($password);
            $this->failed(null, $email, 'unknown_account', $addressKey, $now);

            return 'failed';
        }
        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until'] . ' UTC') > $now) {
            PasswordHasher::verifyDummy($password);
            $this->failed($user, $email, 'locked', $addressKey, $now, countAgainstAccount: false);

            return 'failed';
        }
        $passwordOk = PasswordHasher::verify($password, (string) $user['password_hash']);
        $this->checkpoint('password-checked');
        if (!$passwordOk) {
            $this->failed($user, $email, 'bad_password', $addressKey, $now);

            return 'failed';
        }
        if ($user['status'] !== 'active') {
            $this->failed($user, $email, 'disabled', $addressKey, $now, countAgainstAccount: false);

            return 'failed';
        }
        if (PasswordHasher::needsRehash((string) $user['password_hash'])) {
            Sql::run($this->pdo, 'UPDATE app_user SET password_hash = ? WHERE id = ?', [PasswordHasher::hash($password), (int) $user['id']]);
        }

        if ($user['role'] === Role::Owner->value) {
            // A lock reached while the password was being checked stops this sign-in too
            // (the code step re-checks it atomically when it completes).
            if ($this->isLocked((int) $user['id'], $now)) {
                $this->failed($user, $email, 'locked', $addressKey, $now, countAgainstAccount: false);

                return 'failed';
            }
            $this->session->regenerate();
            $stage = $user['totp_enabled_at'] === null ? 'enroll' : 'totp';
            $this->session->set('pending', ['uid' => (int) $user['id'], 'epoch' => (int) $user['session_epoch'], 'stage' => $stage, 'at' => $now, 'address' => $addressKey]);

            return $stage;
        }
        $refusal = $this->complete($user, $addressKey, $now, 'password');
        if ($refusal !== null) {
            $this->failed($user, $email, $refusal, $addressKey, $now, countAgainstAccount: false);

            return 'failed';
        }

        return 'ok';
    }

    /** Step 2 for an enrolled Owner. Returns 'ok', 'failed' or 'expired'. */
    public function attemptTotp(string $code): string
    {
        $pending = $this->pending('totp');
        if ($pending === null) {
            return 'expired';
        }
        $user = Sql::row($this->pdo, 'SELECT * FROM app_user WHERE id = ?', [$pending['uid']]);
        $now = $this->clock->now();
        // A lock that began after the password step (failures from anywhere) stops the code step too.
        if ($user === null || $user['status'] !== 'active' || (int) $user['session_epoch'] !== $pending['epoch'] || $user['totp_secret'] === null || $this->isLocked((int) $user['id'], $now)) {
            $this->session->remove('pending');

            return 'expired';
        }
        $step = Totp::match((string) $user['totp_secret'], $code, $now, $user['totp_last_step'] === null ? null : (int) $user['totp_last_step']);
        $this->checkpoint('totp-checked');
        if ($step === null) {
            $this->failed($user, (string) $user['email'], 'bad_totp', $pending['address'], $now);
            if ($this->isLocked((int) $user['id'], $now)) {
                $this->session->remove('pending');

                return 'expired';
            }

            return 'failed';
        }
        // Spends the code: of concurrent requests with the same code, only the first changes the row.
        $refusal = $this->complete($user, $pending['address'], $now, 'password+totp', fn(): bool => Sql::run(
            $this->pdo,
            'UPDATE app_user SET totp_last_step = ? WHERE id = ? AND (totp_last_step IS NULL OR totp_last_step < ?)',
            [$step, (int) $user['id'], $step],
        )->rowCount() === 1);
        if ($refusal === 'claimed') {
            // Another request spent this code first: a replay, counted like any wrong code.
            $this->failed($user, (string) $user['email'], 'totp_replay', $pending['address'], $now);
            if ($this->isLocked((int) $user['id'], $now)) {
                $this->session->remove('pending');

                return 'expired';
            }

            return 'failed';
        }
        if ($refusal !== null) {
            $this->session->remove('pending');
            $this->failed($user, (string) $user['email'], $refusal, $pending['address'], $now, countAgainstAccount: false);

            return 'expired';
        }

        return 'ok';
    }

    /** The secret an Owner without TOTP is enrolling; created once per pending sign-in and kept only in the session. */
    public function enrollmentSecret(): ?string
    {
        $pending = $this->pending('enroll');
        if ($pending === null) {
            return null;
        }
        $secret = $this->session->get('enroll_secret');
        if (!\is_string($secret)) {
            $secret = Totp::generateSecret();
            $this->session->set('enroll_secret', $secret);
        }

        return $secret;
    }

    public function enrollmentAccount(): ?string
    {
        $pending = $this->pending('enroll');

        return $pending === null ? null : (string) Sql::scalar($this->pdo, 'SELECT email FROM app_user WHERE id = ?', [$pending['uid']]);
    }

    /** Confirms enrolment with a first code from the app, then signs in. Returns 'ok', 'failed' or 'expired'. */
    public function confirmEnrollment(string $code): string
    {
        $pending = $this->pending('enroll');
        $secret = $this->session->get('enroll_secret');
        if ($pending === null || !\is_string($secret)) {
            return 'expired';
        }
        $user = Sql::row($this->pdo, 'SELECT * FROM app_user WHERE id = ?', [$pending['uid']]);
        $now = $this->clock->now();
        if ($user === null || $user['status'] !== 'active' || (int) $user['session_epoch'] !== $pending['epoch'] || $user['totp_enabled_at'] !== null || $this->isLocked((int) $user['id'], $now)) {
            $this->session->remove('pending');
            $this->session->remove('enroll_secret');

            return 'expired';
        }
        $step = Totp::match($secret, $code, $now, null);
        $this->checkpoint('enrolment-checked');
        if ($step === null) {
            $this->failed($user, (string) $user['email'], 'bad_totp_enrolment', $pending['address'], $now);

            return $this->isLocked((int) $user['id'], $now) ? 'expired' : 'failed';
        }
        // Stores this authenticator only if no concurrent enrolment got there first; the enrolment is
        // audited in the same transaction, so a losing request records no success at all.
        $refusal = $this->complete($user, $pending['address'], $now, 'password+totp', function () use ($user, $secret, $step): bool {
            $enrolled = Sql::run($this->pdo, 'UPDATE app_user SET totp_secret = ?, totp_enabled_at = UTC_TIMESTAMP(), totp_last_step = ? WHERE id = ? AND totp_enabled_at IS NULL', [$secret, $step, (int) $user['id']])->rowCount() === 1;
            if ($enrolled) {
                $this->audit->record(new Actor((int) $user['id'], Role::Owner), 'auth.totp_enrolled', 'app_user', (int) $user['id'], (string) $user['email']);
            }

            return $enrolled;
        });
        $this->session->remove('enroll_secret');
        if ($refusal !== null) {
            $this->session->remove('pending');
            $this->failed($user, (string) $user['email'], $refusal === 'claimed' ? 'enrolment_superseded' : $refusal, $pending['address'], $now, countAgainstAccount: false);

            return 'expired';
        }

        return 'ok';
    }

    /** The signed-in user for this request, or null (and the session cleared) when it has ended. */
    public function current(): ?Actor
    {
        $auth = $this->session->get('auth');
        if (!\is_array($auth) || !\is_int($auth['uid'] ?? null) || !\is_int($auth['epoch'] ?? null) || !\is_int($auth['at'] ?? null) || !\is_int($auth['seen'] ?? null)) {
            return null;
        }
        $now = $this->clock->now();
        $user = Sql::row($this->pdo, 'SELECT id, role, status, session_epoch FROM app_user WHERE id = ?', [$auth['uid']]);
        $reason = $user === null ? 'account_unavailable' : match (true) {
            $user['status'] !== 'active' => 'account_unavailable',
            (int) $user['session_epoch'] !== $auth['epoch'] => 'credentials_changed',
            $now - $auth['seen'] > self::IDLE => 'idle_timeout',
            $now - $auth['at'] > self::ABSOLUTE => 'absolute_timeout',
            default => null,
        };
        if ($user === null || $reason !== null) {
            $this->audit->record(null, 'auth.session_ended', 'app_user', $auth['uid'], null, null, ['reason' => $reason]);
            $this->session->destroy();

            return null;
        }
        $auth['seen'] = $now;
        $this->session->set('auth', $auth);

        return new Actor((int) $user['id'], Role::from((string) $user['role']));
    }

    /** @return array{email: string, display_name: string, role: string}|null */
    public function profile(Actor $actor): ?array
    {
        $row = Sql::row($this->pdo, 'SELECT email, display_name, role FROM app_user WHERE id = ?', [$actor->userId]);

        return $row === null ? null : ['email' => (string) $row['email'], 'display_name' => (string) $row['display_name'], 'role' => (string) $row['role']];
    }

    public function signOut(): void
    {
        $auth = $this->session->get('auth');
        if (\is_array($auth) && \is_int($auth['uid'] ?? null)) {
            $role = Sql::scalar($this->pdo, 'SELECT role FROM app_user WHERE id = ?', [$auth['uid']]);
            $this->audit->record($role === null ? null : new Actor($auth['uid'], Role::from((string) $role)), 'auth.sign_out', 'app_user', $auth['uid'], null);
        }
        $this->session->destroy();
    }

    /**
     * Finishes a sign-in in one transaction that holds the account row: the
     * account must still be active, on the same session epoch and not locked
     * (a lock may have begun while the credential was being checked), and
     * `$claim` (spend the TOTP step, store the enrolment) must change the row.
     * Only then are the counters reset and the success audited, in the same
     * transaction; the session is created after the commit.
     *
     * @param array<string, scalar|null> $user
     * @param (\Closure(): bool)|null $claim
     *
     * @return 'unavailable'|'locked'|'claimed'|null null when signed in
     */
    private function complete(array $user, string $addressKey, int $now, string $method, ?\Closure $claim = null): ?string
    {
        $id = (int) $user['id'];
        $refusal = Transaction::run($this->pdo, function () use ($user, $id, $addressKey, $now, $method, $claim): ?string {
            $row = Sql::row($this->pdo, 'SELECT status, session_epoch, locked_until FROM app_user WHERE id = ? FOR UPDATE', [$id]);
            if ($row === null || $row['status'] !== 'active' || (int) $row['session_epoch'] !== (int) $user['session_epoch']) {
                return 'unavailable';
            }
            if (self::lockedAt($row['locked_until'], $now)) {
                return 'locked';
            }
            if ($claim !== null && !$claim()) {
                return 'claimed';
            }
            Sql::run($this->pdo, 'UPDATE app_user SET failed_logins = 0, locked_until = NULL, last_login_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
            Sql::run($this->pdo, 'DELETE FROM login_throttle WHERE subject_hash = ?', [$addressKey]);
            $this->audit->record(new Actor($id, Role::from((string) $user['role'])), 'auth.sign_in', 'app_user', $id, (string) $user['email'], null, ['method' => $method]);

            return null;
        });
        if ($refusal !== null) {
            return $refusal;
        }
        $this->session->remove('pending');
        $this->session->regenerate();
        $this->session->set('auth', ['uid' => $id, 'epoch' => (int) $user['session_epoch'], 'at' => $now, 'seen' => $now]);
        (new Csrf($this->session))->rotate();

        return null;
    }

    /**
     * Records a failure. The account counter is read and written under a lock
     * on the account row, so concurrent failures are all counted (no lost
     * update) and the lock length follows the true count.
     *
     * @param array<string, scalar|null>|null $user
     */
    private function failed(?array $user, string $email, string $reason, string $addressKey, int $now, bool $countAgainstAccount = true): void
    {
        $after = ['reason' => $reason];
        if ($user !== null && $countAgainstAccount) {
            $id = (int) $user['id'];
            $lockedUntil = Transaction::run($this->pdo, function () use ($id, $now): ?int {
                $failures = Sql::int($this->pdo, 'SELECT failed_logins FROM app_user WHERE id = ? FOR UPDATE', [$id]) + 1;
                if ($failures < self::LOCK_AFTER) {
                    Sql::run($this->pdo, 'UPDATE app_user SET failed_logins = ? WHERE id = ?', [$failures, $id]);

                    return null;
                }
                $until = $now + min(self::LOCK_MAX, self::LOCK_BASE * 2 ** min(16, $failures - self::LOCK_AFTER));
                Sql::run($this->pdo, 'UPDATE app_user SET failed_logins = ?, locked_until = ? WHERE id = ?', [$failures, gmdate('Y-m-d H:i:s', $until), $id]);

                return $until;
            });
            if ($lockedUntil !== null) {
                $after['locked_until'] = gmdate('Y-m-d H:i:s', $lockedUntil);
            }
        }
        $this->recordAddressFailure($addressKey, $now);
        // The attempted identifier is recorded only when it looks like an address, so a password typed into the wrong field never lands in the log.
        $label = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '(not an email address)';
        $this->audit->record(null, 'auth.sign_in_failed', 'app_user', $user === null ? null : (int) $user['id'], $label, null, $after);
    }

    private function isLocked(int $userId, int $now): bool
    {
        return self::lockedAt(Sql::scalar($this->pdo, 'SELECT locked_until FROM app_user WHERE id = ?', [$userId]), $now);
    }

    private static function lockedAt(mixed $until, int $now): bool
    {
        return \is_string($until) && strtotime($until . ' UTC') > $now;
    }

    private function addressBlocked(string $key, int $now): bool
    {
        $until = Sql::scalar($this->pdo, 'SELECT blocked_until FROM login_throttle WHERE subject_hash = ?', [$key]);

        return $until !== null && strtotime((string) $until . ' UTC') > $now;
    }

    /**
     * Counts a failure from one client address in a single atomic upsert, so
     * concurrent failures from the same address are all counted and never
     * deadlock (an insert-then-lock pair takes shared locks first). A new
     * window never lifts a block that is still running.
     *
     * Each assignment reads only columns assigned after it, so the result is
     * the same whether the server evaluates them against the old row (SQL
     * standard) or left to right (MariaDB/MySQL).
     */
    private function recordAddressFailure(string $key, int $now): void
    {
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $windowOpened = gmdate('Y-m-d H:i:s', $now - self::IP_WINDOW);
        Sql::run(
            $this->pdo,
            'INSERT INTO login_throttle (subject_hash, failures, window_started_at, blocked_until) VALUES (?, 1, ?, NULL)
             ON DUPLICATE KEY UPDATE
               blocked_until = CASE
                 WHEN window_started_at < ? THEN (CASE WHEN blocked_until > ? THEN blocked_until ELSE NULL END)
                 WHEN failures + 1 > ? THEN ?
                 ELSE blocked_until END,
               failures = CASE WHEN window_started_at < ? THEN 1 ELSE failures + 1 END,
               window_started_at = CASE WHEN window_started_at < ? THEN ? ELSE window_started_at END',
            [$key, $stamp, $windowOpened, $stamp, self::IP_LIMIT, gmdate('Y-m-d H:i:s', $now + self::IP_BLOCK), $windowOpened, $windowOpened, $stamp],
        );
        // Old windows of other addresses are purged opportunistically; under contention it simply waits for the next failure.
        try {
            Sql::run($this->pdo, 'DELETE FROM login_throttle WHERE window_started_at < ? AND (blocked_until IS NULL OR blocked_until < ?)', [gmdate('Y-m-d H:i:s', $now - 86400), $stamp]);
        } catch (\PDOException $e) {
            if (!\in_array($e->getCode(), ['40001', 'HY000'], true) || !str_contains($e->getMessage(), 'lock')) {
                throw $e;
            }
        }
    }

    private function checkpoint(string $at): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($at);
        }
    }

    /** @return array{uid: int, epoch: int, stage: string, at: int, address: string}|null */
    private function pending(string $stage): ?array
    {
        $p = $this->session->get('pending');
        if (!\is_array($p) || ($p['stage'] ?? null) !== $stage || !\is_int($p['uid'] ?? null) || !\is_int($p['epoch'] ?? null) || !\is_int($p['at'] ?? null) || !\is_string($p['address'] ?? null)) {
            return null;
        }
        if ($this->clock->now() - $p['at'] > self::PENDING) {
            $this->session->remove('pending');
            $this->session->remove('enroll_secret');

            return null;
        }

        return ['uid' => $p['uid'], 'epoch' => $p['epoch'], 'stage' => $stage, 'at' => $p['at'], 'address' => $p['address']];
    }
}
