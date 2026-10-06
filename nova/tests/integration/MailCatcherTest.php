<?php

declare(strict_types=1);

namespace Nova\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** Local mail goes to the mail catcher, which must accept SMTP connections. */
final class MailCatcherTest extends TestCase
{
    public function testSmtpGreets(): void
    {
        $socket = fsockopen(Env::get('NOVA_SMTP_HOST'), (int) Env::get('NOVA_SMTP_PORT'), $errno, $errstr, 5.0);
        self::assertNotFalse($socket, "SMTP connection failed: {$errstr}");

        $banner = (string) fgets($socket);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        self::assertStringStartsWith('220', $banner);
    }
}
