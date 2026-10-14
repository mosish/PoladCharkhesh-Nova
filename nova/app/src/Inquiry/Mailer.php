<?php

declare(strict_types=1);

namespace Nova\Inquiry;

/** Sends one plain-text notification; throws on any failure. */
interface Mailer
{
    public function send(string $to, string $subject, string $body): void;
}
