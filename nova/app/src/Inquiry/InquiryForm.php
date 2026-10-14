<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Designation\Normaliser;
use Nova\Designation\RequestedDesignation;
use Nova\Http\Request;

/**
 * The submitted form, validated on the server (05 §13). Values are kept as
 * the visitor typed them (trimmed only), so a recoverable failure can show
 * them again unchanged; the requested designation is never rewritten.
 *
 * The form's product/item/match fields are what the page claimed. They are
 * never trusted as the match (E-21): `InquiryMatch` recomputes it.
 *
 * Error codes per field: required, too_long, phone, invalid, code_or_message.
 */
final readonly class InquiryForm
{
    public const array LIMITS = ['name' => 120, 'phone' => 40, 'code' => RequestedDesignation::MAX_LENGTH, 'qty' => 20, 'msg' => 2000];

    /**
     * @param array{name: string, phone: string, code: string, qty: string, msg: string} $values
     * @param array<string, string> $errors field => error code
     * @param array{product: string, item: string, match: string} $claimed
     */
    private function __construct(
        public array $values,
        public array $errors,
        public array $claimed,
        public string $honeypot,
        public string $token,
        public bool $malformed,
    ) {}

    public static function fromRequest(Request $r): self
    {
        $malformed = false;
        $field = static function (string $name) use ($r, &$malformed): string {
            $value = $r->post[$name] ?? '';
            if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                $malformed = true;

                return '';
            }

            return $value;
        };

        $values = [];
        foreach (array_keys(self::LIMITS) as $name) {
            $values[$name] = self::trim($field($name));
        }
        $claimed = ['product' => mb_substr($field('product'), 0, 80), 'item' => mb_substr($field('item'), 0, 80), 'match' => mb_substr($field('match'), 0, 32)];
        $honeypot = $field('website');
        $token = $field('token');

        $errors = [];
        foreach ($values as $name => $value) {
            $multiline = $name === 'msg';
            if (preg_match($multiline ? '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u' : '/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', $value) === 1) {
                $errors[$name] = 'invalid';
            } elseif (mb_strlen($value, 'UTF-8') > self::LIMITS[$name]) {
                $errors[$name] = 'too_long';
            }
        }
        foreach (['name', 'phone'] as $name) {
            if (!isset($errors[$name]) && $values[$name] === '') {
                $errors[$name] = 'required';
            }
        }
        if (!isset($errors['phone']) && $values['phone'] !== '' && !self::phoneLike($values['phone'])) {
            $errors['phone'] = 'phone';
        }
        // The form's own hint: no code → describe the application instead.
        if (!isset($errors['code']) && !isset($errors['msg']) && $values['code'] === '' && $values['msg'] === '') {
            $errors['msg'] = 'code_or_message';
        }

        /** @var array{name: string, phone: string, code: string, qty: string, msg: string} $values */
        return new self($values, $errors, $claimed, $honeypot, $token, $malformed);
    }

    public function valid(): bool
    {
        return $this->errors === [] && !$this->malformed;
    }

    /**
     * Digits in any script (Persian, Arabic-Indic, Latin), with the usual
     * separators, and 7–15 digits in all (E.164 allows at most 15).
     */
    public static function phoneLike(string $phone): bool
    {
        if (preg_match('/^\+?[\p{Nd}\s().\-\x{200C}]+$/u', $phone) !== 1) {
            return false;
        }
        $digits = preg_match_all('/\p{Nd}/u', $phone);

        return $digits >= 7 && $digits <= 15;
    }

    /** Leading/trailing whitespace only, as the designation rules do (docs/designation-matching.md §1). */
    private static function trim(string $value): string
    {
        $ws = Normaliser::WHITESPACE;

        return (string) preg_replace("/^[{$ws}]+|[{$ws}]+\$/u", '', $value);
    }
}
