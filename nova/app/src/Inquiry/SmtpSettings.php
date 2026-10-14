<?php

declare(strict_types=1);

namespace Nova\Inquiry;

/** The SMTP mailbox that sends notifications. `encryption`: '' (none, the local catcher), 'tls' (STARTTLS) or 'ssl'. */
final readonly class SmtpSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public string $user = '',
        #[\SensitiveParameter]
        public string $password = '',
        public string $encryption = '',
    ) {}

    public function configured(): bool
    {
        return $this->host !== '';
    }
}
