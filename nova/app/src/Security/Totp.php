<?php

declare(strict_types=1);

namespace Nova\Security;

/**
 * Time-based one-time passwords for the Owner (RFC 6238 over RFC 4226;
 * E-10): HMAC-SHA1, 6 digits, 30-second steps, one step of clock drift either
 * way. The step a code matched is returned so the caller can refuse it a
 * second time (replay). Secrets are 160 random bits in base32 (RFC 4648),
 * the form authenticator apps accept for manual entry.
 */
final class Totp
{
    public const int PERIOD = 30;
    public const int DIGITS = 6;
    public const int DRIFT_STEPS = 1;
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The code for one time step (RFC 4226 dynamic truncation). */
    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hmac = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = \ord($hmac[19]) & 0x0F;
        $binary = ((\ord($hmac[$offset]) & 0x7F) << 24) | (\ord($hmac[$offset + 1]) << 16) | (\ord($hmac[$offset + 2]) << 8) | \ord($hmac[$offset + 3]);

        return str_pad((string) ($binary % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    /**
     * The step `$code` matches near `$time`, or null. Steps at or before
     * `$lastUsedStep` never match again. Comparison is constant-time.
     */
    public static function match(string $secret, string $code, int $time, ?int $lastUsedStep): ?int
    {
        $code = strtr(preg_replace('/\s+/u', '', $code) ?? '', ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if (preg_match('/^[0-9]{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $now = self::step($time);
        $found = null;
        for ($step = $now - self::DRIFT_STEPS; $step <= $now + self::DRIFT_STEPS; ++$step) {
            if (hash_equals(self::code($secret, $step), $code) && ($lastUsedStep === null || $step > $lastUsedStep)) {
                $found ??= $step;
            }
        }

        return $found;
    }

    /** The otpauth URI authenticator apps import (label and issuer are not secret). */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?' . http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC3986);
    }

    /** The secret in groups of four, for typing by hand. */
    public static function grouped(string $secret): string
    {
        return implode(' ', str_split($secret, 4));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(str_replace([' ', '='], '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $value = strpos(self::ALPHABET, $char);
            if ($value === false) {
                throw new \InvalidArgumentException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (\strlen($byte) === 8) {
                $out .= \chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
