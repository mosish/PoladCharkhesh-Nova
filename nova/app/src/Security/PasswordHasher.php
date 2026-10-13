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

    /**
     * Fixed hashes of random, discarded passwords, at the current default
     * parameters (Argon2id m=65536,t=4,p=1; bcrypt cost 12). An unknown
     * account is verified against one of these, so it costs exactly one
     * verification, like a known account, and never an extra hash computed in
     * the request (which doubled the time and revealed that the account does
     * not exist). They protect no account; PasswordTimingTest fails if PHP's
     * defaults move away from them.
     */
    private const string DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$N1YzV2NEMXBRRTc0bVZ5SQ$cyLyDts8/9t0XtmEg/1GVJSICJHNupnl5ahZ3VOeXIQ';
    private const string DUMMY_BCRYPT = '$2y$12$pgR9FWf1Fo/OHGx9lJvHkeXE2g0klQ8LeGk7XW3jjbrFDCN.VN/Ne';

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

    /** Spends the same work as verify() on a real hash, for an unknown account (including no work for an over-long input). */
    public static function verifyDummy(string $password): void
    {
        self::verify($password, self::dummyHash());
    }

    public static function dummyHash(): string
    {
        return self::algorithm() === PASSWORD_BCRYPT ? self::DUMMY_BCRYPT : self::DUMMY_ARGON2ID;
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
