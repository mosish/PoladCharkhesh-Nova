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
    ) {}

    public static function development(int $port = 8080): self
    {
        return new self(['fa' => "http://fa.nova.localhost:{$port}", 'en' => "http://en.nova.localhost:{$port}"], true, false);
    }

    public function url(string $lang, string $path): string
    {
        return $this->hosts[$lang === 'fa' ? 'fa' : 'en'] . $path;
    }
}
