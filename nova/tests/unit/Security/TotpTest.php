<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Security;

use Nova\Security\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** RFC 6238 appendix B (SHA-1 key "12345678901234567890"), the last six of its eight digits. */
    private const string RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return iterable<string, array{int, string}> */
    public static function rfcVectors(): iterable
    {
        yield '59' => [59, '287082'];
        yield '1111111109' => [1111111109, '081804'];
        yield '1111111111' => [1111111111, '050471'];
        yield '1234567890' => [1234567890, '005924'];
        yield '2000000000' => [2000000000, '279037'];
        yield '20000000000' => [20000000000, '353130'];
    }

    #[DataProvider('rfcVectors')]
    public function testRfc6238Vectors(int $time, string $code): void
    {
        self::assertSame('12345678901234567890', Totp::base32Decode(self::RFC_SECRET));
        self::assertSame($code, Totp::code(self::RFC_SECRET, Totp::step($time)));
    }

    public function testMatchAllowsOneStepOfDriftAndNoMore(): void
    {
        $secret = Totp::generateSecret();
        $t = 1_800_000_000;
        $step = Totp::step($t);
        self::assertSame($step, Totp::match($secret, Totp::code($secret, $step), $t, null));
        self::assertSame($step - 1, Totp::match($secret, Totp::code($secret, $step - 1), $t, null));
        self::assertSame($step + 1, Totp::match($secret, Totp::code($secret, $step + 1), $t, null));
        self::assertNull(Totp::match($secret, Totp::code($secret, $step - 2), $t, null));
        self::assertNull(Totp::match($secret, Totp::code($secret, $step + 2), $t, null));
    }

    public function testACodeIsNeverAcceptedTwice(): void
    {
        $secret = Totp::generateSecret();
        $t = 1_800_000_000;
        $code = Totp::code($secret, Totp::step($t));
        $used = Totp::match($secret, $code, $t, null);
        self::assertNotNull($used);
        self::assertNull(Totp::match($secret, $code, $t, $used), 'replay of the same step');
        self::assertNull(Totp::match($secret, Totp::code($secret, $used - 1), $t, $used), 'an older step after a newer one was used');
    }

    public function testInputIsDigitsOnlyPersianDigitsAndSpacesTolerated(): void
    {
        $secret = Totp::generateSecret();
        $t = 1_800_000_000;
        $code = Totp::code($secret, Totp::step($t));
        $persian = strtr($code, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        self::assertNotNull(Totp::match($secret, $persian, $t, null));
        self::assertNotNull(Totp::match($secret, substr($code, 0, 3) . ' ' . substr($code, 3), $t, null));
        foreach (['', '12345', '1234567', 'abcdef', $code . 'x'] as $bad) {
            self::assertNull(Totp::match($secret, $bad, $t, null), "'{$bad}'");
        }
    }

    public function testSecretsAreRandom160BitBase32(): void
    {
        $a = Totp::generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        self::assertSame(20, \strlen(Totp::base32Decode($a)));
        self::assertNotSame($a, Totp::generateSecret());
        self::assertSame($a, Totp::base32Encode(Totp::base32Decode($a)));
        self::assertSame(str_replace(' ', '', Totp::grouped($a)), $a);
    }

    public function testTheOtpauthUriCarriesNoSurprises(): void
    {
        $uri = Totp::uri('JBSWY3DPEHPK3PXP', 'owner@example.test', 'Polad Charkhesh Nova');
        self::assertStringStartsWith('otpauth://totp/Polad%20Charkhesh%20Nova%3Aowner%40example.test?secret=JBSWY3DPEHPK3PXP&', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
