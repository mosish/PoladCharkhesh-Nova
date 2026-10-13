<?php

declare(strict_types=1);

namespace Nova\Publish;

/**
 * Per-environment build settings. Hosts: Persian on the .ir host and English
 * on the .com host (D6), x-default = .com (D10). Non-production builds are
 * noindex throughout (staging, development; 05 §22).
 */
final readonly class BuildConfig
{
    /** @param array{fa: string, en: string} $hosts absolute origins, no trailing slash */
    public function __construct(
        public array $hosts,
        public bool $includeSamples,
        public bool $indexable,
    ) {
        if ($includeSamples && $indexable) {
            throw new \InvalidArgumentException('A build containing samples cannot be indexable.');
        }
        foreach ($hosts as $origin) {
            $parts = parse_url($origin);
            if ($parts === false || !isset($parts['scheme'], $parts['host']) || !\in_array($parts['scheme'], ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
                || ($parts['path'] ?? '') !== '') {
                throw new \InvalidArgumentException('Language hosts must be absolute HTTP(S) origins without paths, credentials, queries or fragments.');
            }
            $host = strtolower(rtrim($parts['host'], '.'));
            if ($indexable && ($parts['scheme'] !== 'https' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
                || $host === 'localhost' || str_ends_with($host, '.localhost'))) {
                throw new \InvalidArgumentException('Indexable builds require HTTPS domain names, not local or IP preview addresses.');
            }
        }
        if (strtolower($hosts['fa']) === strtolower($hosts['en'])) {
            throw new \InvalidArgumentException('Persian and English must use distinct origins (D6).');
        }
    }

    public static function development(int $port = 8080): self
    {
        return new self(['fa' => "http://fa.nova.localhost:{$port}", 'en' => "http://en.nova.localhost:{$port}"], true, false);
    }

    public function url(string $lang, string $path): string
    {
        return $this->hosts[$lang === 'fa' ? 'fa' : 'en'] . $path;
    }
}
