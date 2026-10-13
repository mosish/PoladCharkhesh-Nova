<?php

declare(strict_types=1);

namespace Nova\Security;

use InvalidArgumentException;
use Nova\Audit\AuditLog;
use Nova\Core\Sql;
use Nova\Core\Transaction;
use PDO;
use RuntimeException;

/**
 * Account administration from the operator command line (05 §14): the first
 * Owner is created once; passwords and TOTP are reset by the documented
 * command-line recovery. Every change ends existing sessions of that account
 * (session_epoch) and is audited with its command-line origin. No default or
 * built-in account exists anywhere.
 */
final readonly class UserAdmin
{
    private AuditLog $audit;

    /** @param array<string, int|string> $origin recorded in the audit entry, e.g. ['origin' => 'cli', 'os_user' => 'deploy'] */
    public function __construct(private PDO $pdo, private array $origin)
    {
        $this->audit = new AuditLog($pdo);
    }

    /** Only while no Owner exists. */
    public function createFirstOwner(string $email, string $displayName, string $password): int
    {
        return Transaction::run($this->pdo, function () use ($email, $displayName, $password): int {
            if (Sql::int($this->pdo, "SELECT COUNT(*) FROM app_user WHERE role = 'owner' FOR UPDATE") > 0) {
                throw new RuntimeException('An Owner already exists; the first Owner can be created only once.');
            }

            return $this->create($email, $displayName, $password, Role::Owner);
        });
    }

    /** Editors are created by the Owner's operator; the Users screen arrives with the Phase 4b Admin. */
    public function createEditor(string $email, string $displayName, string $password): int
    {
        if (Sql::int($this->pdo, "SELECT COUNT(*) FROM app_user WHERE role = 'owner'") === 0) {
            throw new RuntimeException('Create the Owner first (user:create-owner).');
        }

        return Transaction::run($this->pdo, fn(): int => $this->create($email, $displayName, $password, Role::Editor));
    }

    public function resetPassword(string $email, string $password): void
    {
        $hash = PasswordHasher::hash($password);
        $id = $this->id($email);
        Sql::run($this->pdo, 'UPDATE app_user SET password_hash = ?, failed_logins = 0, locked_until = NULL, session_epoch = session_epoch + 1, row_version = row_version + 1 WHERE id = ?', [$hash, $id]);
        $this->audit->record(null, 'user.password_reset', 'app_user', $id, $email, null, ['by' => $this->origin]);
    }

    /** The Owner enrols a new authenticator at the next sign-in. */
    public function resetTotp(string $email): void
    {
        $id = $this->id($email);
        Sql::run($this->pdo, 'UPDATE app_user SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL, session_epoch = session_epoch + 1, row_version = row_version + 1 WHERE id = ?', [$id]);
        $this->audit->record(null, 'user.totp_reset', 'app_user', $id, $email, null, ['by' => $this->origin]);
    }

    /** @return list<array{email: string, display_name: string, role: string, status: string, totp: bool, locked: bool}> */
    public function list(): array
    {
        $out = [];
        foreach (Sql::rows($this->pdo, 'SELECT email, display_name, role, status, totp_enabled_at, locked_until FROM app_user ORDER BY id') as $r) {
            $out[] = [
                'email' => (string) $r['email'], 'display_name' => (string) $r['display_name'], 'role' => (string) $r['role'], 'status' => (string) $r['status'],
                'totp' => $r['totp_enabled_at'] !== null, 'locked' => $r['locked_until'] !== null && strtotime((string) $r['locked_until'] . ' UTC') > time(),
            ];
        }

        return $out;
    }

    private function create(string $email, string $displayName, string $password, Role $role): int
    {
        $email = trim($email);
        $displayName = trim($displayName);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email, 'UTF-8') > 254) {
            throw new InvalidArgumentException('A valid email address is required.');
        }
        if ($displayName === '' || mb_strlen($displayName, 'UTF-8') > 100) {
            throw new InvalidArgumentException('A display name (at most 100 characters) is required.');
        }
        if (Sql::int($this->pdo, 'SELECT COUNT(*) FROM app_user WHERE email = ?', [$email]) > 0) {
            throw new RuntimeException('An account with that email address already exists.');
        }
        Sql::run($this->pdo, 'INSERT INTO app_user (email, display_name, role, password_hash) VALUES (?, ?, ?, ?)', [$email, $displayName, $role->value, PasswordHasher::hash($password)]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->record(null, 'user.create', 'app_user', $id, $email, null, ['role' => $role->value, 'by' => $this->origin]);

        return $id;
    }

    private function id(string $email): int
    {
        $id = Sql::scalar($this->pdo, 'SELECT id FROM app_user WHERE email = ?', [trim($email)]);
        if ($id === null) {
            throw new RuntimeException('No account has that email address.');
        }

        return (int) $id;
    }
}
