<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Core;

use Nova\Core\Config;
use Nova\Core\Environment;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testEnvironmentFailsClosedToProduction(): void
    {
        self::assertSame(Environment::Production, Environment::fromValue(null));
        self::assertSame(Environment::Production, Environment::fromValue(''));
        self::assertSame(Environment::Production, Environment::fromValue('Development'));
        self::assertSame(Environment::Production, Environment::fromValue(1));
        self::assertSame(Environment::Development, Environment::fromValue('development'));
        self::assertSame(Environment::Staging, Environment::fromValue('staging'));
    }

    public function testConfigFileIsTheOnlySourceWhenPresent(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg');
        self::assertIsString($file);
        file_put_contents($file, "<?php return ['database' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => 'p']];");
        putenv('NOVA_ENV=development');
        try {
            $config = Config::load($file);
        } finally {
            putenv('NOVA_ENV=development');
            unlink($file);
        }
        // The file sets no environment, so it is production even though NOVA_ENV says development.
        self::assertSame(Environment::Production, $config->environment);
        self::assertSame('n', $config->database->name);
    }
}
