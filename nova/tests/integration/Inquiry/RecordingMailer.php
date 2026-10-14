<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Inquiry;

use Nova\Inquiry\Mailer;
use RuntimeException;

/** Records notifications instead of sending them; fails on demand like an unreachable SMTP server. */
final class RecordingMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string}> */
    public array $sent = [];
    public bool $fail = false;

    public function send(string $to, string $subject, string $body): void
    {
        if ($this->fail) {
            throw new RuntimeException('SMTP: connect() failed (test double)');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }
}
