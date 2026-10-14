<?php

declare(strict_types=1);

namespace Nova\Inquiry;

/**
 * The minimum-fill-time check (05 §13). Pages are static, so the start time
 * comes from the endpoint itself: a token `v1.<unix time>.<mac>` whose MAC
 * binds the time and the language. The visitor's clock is never compared
 * with the server's, and a token cannot be back-dated without the key.
 *
 * With JavaScript the form asks for a token when it is first used; without
 * it the first submission returns the form with the entered values and a
 * token to confirm (`InquiryEndpoint`).
 */
final readonly class FillToken
{
    public function __construct(#[\SensitiveParameter] private string $key) {}

    public function issue(string $lang, int $now): string
    {
        return "v1.{$now}." . $this->mac($now, $lang);
    }

    /** The time an authentic token for `$lang` was issued, or null. */
    public function issuedAt(string $token, string $lang): ?int
    {
        if (preg_match('/^v1\.([0-9]{1,12})\.([0-9a-f]{64})$/', $token, $m) !== 1) {
            return null;
        }
        $time = (int) $m[1];

        return hash_equals($this->mac($time, $lang), $m[2]) ? $time : null;
    }

    private function mac(int $time, string $lang): string
    {
        return hash_hmac('sha256', "inquiry-fill|{$time}|{$lang}", $this->key);
    }
}
