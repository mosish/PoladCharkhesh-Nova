<?php

declare(strict_types=1);

namespace Nova\Http;

/**
 * An Admin response. Every one carries the Admin security headers (05 §15):
 * a CSP that allows only this origin and no inline script or style, no
 * framing, nosniff, no referrer, no caching, and noindex.
 */
final class Response
{
    public const string CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";

    /** @param array<string, string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
    ) {
        $this->headers += [
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Same-origin, path-only redirects (303 after a POST). */
    public static function redirect(string $path, int $status = 303): self
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            $path = '/admin/';
        }

        return new self($status, '', ['Location' => $path]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
