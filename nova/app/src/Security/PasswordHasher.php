<?php

declare(strict_types=1);

namespace Nova\Security;

use InvalidArgumentException;

/**
 * Admin passwords (05 §14): Argon2id where the PHP build has it, else bcrypt
 * (which host has which is a probe item). Hashes upgrade on the next
 * successful sign-in when the algorithm or its cost changes.
 */
final class PasswordHasher
{
    /** Length rules only (no composition rules); the upper bound keeps hashing cheap to refuse. */
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 256;

    /** A real hash of a random password: verified against when the account does not exist, so timing does not tell. */
    private static ?string $dummy = null;

    public static function hash(string $password): string
    {
        self::assertAcceptable($password);

        return password_hash($password, self::algorithm());
    }

    public static function verify(string $password, string $hash): bool
    {
        return \strlen($password) <= self::MAX_LENGTH && password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algorithm());
    }

    /** Spends the same work as a real check, for an unknown account. */
    public static function verifyDummy(string $password): void
    {
        self::$dummy ??= password_hash(bin2hex(random_bytes(16)), self::algorithm());
        password_verify(substr($password, 0, self::MAX_LENGTH), self::$dummy);
    }

    public static function assertAcceptable(string $password): void
    {
        $length = mb_strlen($password, 'UTF-8');
        if ($length < self::MIN_LENGTH || \strlen($password) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A password must be at least ' . self::MIN_LENGTH . ' characters (and at most ' . self::MAX_LENGTH . ' bytes).');
        }
    }

    public static function algorithm(): string
    {
        return \defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }
}
