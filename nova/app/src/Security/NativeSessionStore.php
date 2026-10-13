<?php

declare(strict_types=1);

namespace Nova\Security;

use RuntimeException;

/**
 * PHP file sessions in Nova's own folder (05 §14), never the host's shared
 * session directory: strict mode (an unknown id is never adopted), cookies
 * only, HttpOnly, Secure, SameSite=Strict, limited to the /admin path.
 * Lifetime is enforced by AdminAuth (idle and absolute timeouts); the
 * cookie itself ends with the browser session.
 */
final class NativeSessionStore implements SessionStore
{
    public const string COOKIE = 'nova_admin';

    public function __construct(
        private readonly string $directory,
        private readonly int $maxLifetime,
        private readonly bool $secureCookie = true,
    ) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the session folder.');
        }
        session_save_path($this->directory);
        session_name(self::COOKIE);
        session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => $this->secureCookie, 'httponly' => true, 'samesite' => 'Strict']);
        $ok = session_start([
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'use_trans_sid' => false,
            'cookie_httponly' => true,
            'cookie_secure' => $this->secureCookie,
            'cookie_samesite' => 'Strict',
            'gc_maxlifetime' => $this->maxLifetime,
            'cache_limiter' => '',
            'lazy_write' => true,
        ]);
        if (!$ok) {
            throw new RuntimeException('Cannot start the session.');
        }
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => '/admin', 'secure' => $this->secureCookie, 'httponly' => true, 'samesite' => 'Strict']);
    }
}
