<?php

declare(strict_types=1);

namespace Nova\Content\RichText;

/**
 * A rich-content link target: an https URL, or an internal reference resolved
 * per language and host at publish (`product:<slug>`, `section:<id>`,
 * `document:<id>`). javascript:, data:, http: and relative URLs never pass.
 */
final readonly class LinkTarget
{
    private function __construct(
        public string $kind,
        public string $value,
    ) {}

    public static function parse(string $target): ?self
    {
        if (preg_match('/^product:([a-z0-9]+(?:-[a-z0-9]+)*)$/', $target, $m) === 1) {
            return new self('product', $m[1]);
        }
        if (preg_match('/^section:([a-z0-9]+(?:-[a-z0-9]+)*)$/', $target, $m) === 1) {
            return new self('section', $m[1]);
        }
        if (preg_match('/^document:([1-9][0-9]{0,18})$/', $target, $m) === 1) {
            return new self('document', $m[1]);
        }
        if (\strlen($target) <= RichText::MAX_URL
            && preg_match('/^https:\/\/[^\s<>"\'`\\\\()]+$/', $target) === 1
            && filter_var($target, FILTER_VALIDATE_URL) !== false
            && \is_string(parse_url($target, PHP_URL_HOST))) {
            return new self('external', $target);
        }

        return null;
    }
}
