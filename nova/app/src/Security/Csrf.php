<?php

declare(strict_types=1);

namespace Nova\Security;

/**
 * CSRF protection for every Admin change (05 §6, §15): a per-session random
 * token in each form, compared in constant time. Defence in depth: a POST
 * whose browser says it came from another site is refused even with a token.
 */
final readonly class Csrf
{
    private const string KEY = 'csrf';

    public function __construct(private SessionStore $session) {}

    public function token(): string
    {
        $token = $this->session->get(self::KEY);
        if (!\is_string($token) || \strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }

        return $token;
    }

    public function valid(?string $submitted): bool
    {
        $token = $this->session->get(self::KEY);

        return \is_string($token) && \is_string($submitted) && $submitted !== '' && hash_equals($token, $submitted);
    }

    /** A new token after sign-in, so one seen before authentication is worthless afterwards. */
    public function rotate(): void
    {
        $this->session->set(self::KEY, bin2hex(random_bytes(32)));
    }
}
