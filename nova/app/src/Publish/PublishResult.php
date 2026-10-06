<?php

declare(strict_types=1);

namespace Nova\Publish;

final readonly class PublishResult
{
    /**
     * @param 'live'|'failed'|'waiting' $status
     * @param list<string> $blockers
     * @param list<string> $warnings
     */
    public function __construct(
        public string $status,
        public ?string $release,
        public array $blockers = [],
        public array $warnings = [],
        public int $waitSeconds = 0,
    ) {}
}
