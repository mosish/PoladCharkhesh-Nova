<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Security;

use InvalidArgumentException;
use Nova\Security\ArraySessionStore;
use Nova\Security\Csrf;
use Nova\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;

final class PasswordAndCsrfTest extends TestCase
{
    public function testArgon2idWhenAvailableAndVerification(): void
    {
        $hash = PasswordHasher::hash('correct horse battery');
        if (\defined('PASSWORD_ARGON2ID')) {
            self::assertStringStartsWith('$argon2id$', $hash);
        } else {
            self::assertStringStartsWith('$2y$', $hash);
        }
        self::assertTrue(PasswordHasher::verify('correct horse battery', $hash));
        self::assertFalse(PasswordHasher::verify('correct horse batterY', $hash));
        self::assertFalse(PasswordHasher::needsRehash($hash));
        self::assertTrue(PasswordHasher::needsRehash(password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])) || !\defined('PASSWORD_ARGON2ID'));
    }

    public function testLengthRules(): void
    {
        foreach (['short', str_repeat('a', 11), str_repeat('a', 257)] as $bad) {
            try {
                PasswordHasher::hash($bad);
                self::fail('accepted a password of ' . \strlen($bad) . ' bytes');
            } catch (InvalidArgumentException) {
            }
        }
        // Twelve Persian letters are twelve characters, though more bytes.
        self::assertTrue(PasswordHasher::verify('گذرواژه‌طولانی', PasswordHasher::hash('گذرواژه‌طولانی')));
        self::assertFalse(PasswordHasher::verify(str_repeat('a', 300), PasswordHasher::hash(str_repeat('a', 20))), 'over-long input is refused before hashing');
        PasswordHasher::verifyDummy('anything at all');
        $this->addToAssertionCount(1);
    }

    public function testCsrfTokenIsPerSessionAndComparedExactly(): void
    {
        $session = new ArraySessionStore();
        $csrf = new Csrf($session);
        $token = $csrf->token();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame($token, $csrf->token(), 'stable within the session');
        self::assertTrue($csrf->valid($token));
        foreach ([null, '', substr($token, 0, 63), strtoupper($token), $token . '0', (new Csrf(new ArraySessionStore()))->token()] as $bad) {
            self::assertFalse($csrf->valid($bad));
        }
        $csrf->rotate();
        self::assertFalse($csrf->valid($token), 'a token from before sign-in is worthless after it');
        self::assertFalse((new Csrf(new ArraySessionStore()))->valid($token), 'no token in the session: nothing is valid');
    }
}
