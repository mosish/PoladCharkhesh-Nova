<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Cli;

use Nova\Cli\Application;
use Nova\Cli\Output;
use Nova\Core\Config;
use Nova\Core\DatabaseConfig;
use Nova\Core\Environment;
use Nova\Core\Paths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The operator command line refuses to run under any web SAPI, before it reads configuration or the database. */
final class ApplicationSapiTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function webRuns(): iterable
    {
        foreach (['apache2handler', 'fpm-fcgi', 'cgi-fcgi', 'litespeed', 'phpdbg'] as $sapi) {
            foreach (['publish', 'publish:rollback', 'publish:recover', 'migrate', 'help'] as $command) {
                yield "{$sapi} {$command}" => [$sapi, $command];
            }
        }
    }

    #[DataProvider('webRuns')]
    public function testANonCliSapiIsRefused(string $sapi, string $command): void
    {
        $output = new Output(false);
        // The database host does not exist: a refused run never gets as far as connecting.
        $app = new Application(new Config(Environment::Development, new DatabaseConfig('nonexistent.invalid', 3306, 'n', 'u', 'p')), Paths::fromHere(), $output, $sapi);

        self::assertSame(2, $app->run(['nova', $command]));
        self::assertSame(['app/bin/nova runs only from the command line (PHP CLI).'], $output->errors);
        self::assertSame([], $output->lines);
    }

    public function testTheCliSapiRuns(): void
    {
        $output = new Output(false);
        $app = new Application(new Config(Environment::Production, new DatabaseConfig('nonexistent.invalid', 3306, 'n', 'u', 'p')), Paths::fromHere(), $output, 'cli');
        self::assertSame(0, $app->run(['nova', 'help']));
        self::assertStringContainsString('Usage', $output->lines[0] ?? '');
    }
}
