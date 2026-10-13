<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Security;

use Nova\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * An unknown account must cost the same password work as a known one, in a
 * fresh process as every web request is: one verification against a hash of
 * the current algorithm and cost, never an extra hash computed on the spot.
 */
final class PasswordTimingTest extends TestCase
{
    public function testTheDummyHashHasTheCostOfARealOne(): void
    {
        $dummy = PasswordHasher::dummyHash();
        self::assertFalse(PasswordHasher::needsRehash($dummy), 'same algorithm and parameters as a freshly made hash');
        self::assertSame(password_get_info(PasswordHasher::hash('a long test passphrase'))['options'], password_get_info($dummy)['options']);
        self::assertFalse(PasswordHasher::verify('', $dummy));
    }

    public function testAnUnknownAccountCostsOneVerificationInAFreshProcess(): void
    {
        $hash = PasswordHasher::hash('the real long passphrase');
        $autoload = \dirname(__DIR__, 3) . '/vendor/autoload.php';
        $measure = static function (string $code) use ($autoload): float {
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg("require '{$autoload}'; {$code}"));
            self::assertIsString($out);

            return (float) trim($out);
        };
        $known = $unknown = [];
        for ($i = 0; $i < 5; ++$i) {
            $known[] = $measure('$t = hrtime(true); Nova\Security\PasswordHasher::verify("a wrong guess here", ' . var_export($hash, true) . '); echo (hrtime(true) - $t) / 1e6;');
            $unknown[] = $measure('$t = hrtime(true); Nova\Security\PasswordHasher::verifyDummy("a wrong guess here"); echo (hrtime(true) - $t) / 1e6;');
        }
        sort($known);
        sort($unknown);
        // Medians; a second hash on the spot doubles the work (ratio ≈ 2). Generous bound against noise.
        self::assertLessThan(1.5, $unknown[2] / $known[2], \sprintf('unknown %.1f ms vs known %.1f ms', $unknown[2], $known[2]));
    }
}
