<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Publish;

use Nova\Core\Config;
use Nova\Core\DatabaseConfig;
use Nova\Core\Environment;
use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Publish\PublishFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Storage (journal, strategy D archive, later backups and sessions) never resolves inside the docroot (finding 1). */
final class PublishFactoryTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nova-factory-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/home/public_html', 0775, true);
        mkdir($this->tmp . '/home/nova-storage', 0775, true);
    }

    protected function tearDown(): void
    {
        Files::removeTree($this->tmp);
    }

    /** @param array<string, string> $paths */
    private function config(Environment $env, array $paths): Config
    {
        return new Config($env, new DatabaseConfig('db', 3306, 'n', 'u', 'p'), ['paths' => $paths]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafe(): iterable
    {
        yield 'inside' => ['public_html', 'public_html/storage'];
        yield 'the docroot itself' => ['public_html', 'public_html'];
        yield 'trailing slash' => ['public_html/', 'public_html/x/'];
        yield 'dot segments' => ['public_html', 'nova-storage/../public_html/st'];
        yield 'symlinked storage pointing inside' => ['public_html', 'link-into-public/st'];
        yield 'symlinked docroot' => ['link-to-public', 'public_html/st'];
    }

    #[DataProvider('unsafe')]
    public function testStorageInsideTheDocrootIsRefusedOutsideDevelopment(string $public, string $storage): void
    {
        symlink($this->tmp . '/home/public_html', $this->tmp . '/home/link-into-public');
        symlink($this->tmp . '/home/public_html', $this->tmp . '/home/link-to-public');
        $home = $this->tmp . '/home/';
        foreach ([Environment::Production, Environment::Staging] as $env) {
            try {
                PublishFactory::locations($this->config($env, ['public' => $home . $public, 'storage' => $home . $storage]), new Paths($this->tmp));
                self::fail("{$env->value} accepted storage {$storage} inside {$public}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('outside the document root', $e->getMessage());
                self::assertStringNotContainsString($this->tmp, $e->getMessage(), 'no server paths in the message');
            }
        }
    }

    public function testSeparateStorageIsAccepted(): void
    {
        $home = $this->tmp . '/home';
        // A sibling whose name merely starts with the docroot's name is not inside it.
        mkdir($home . '/public_html2');
        foreach (['nova-storage', 'public_html2/storage', 'nova-storage/not-yet-created'] as $storage) {
            [$public, $resolved] = PublishFactory::locations($this->config(Environment::Production, ['public' => $home . '/public_html', 'storage' => "{$home}/{$storage}"]), new Paths($this->tmp));
            self::assertSame($home . '/public_html', $public);
            self::assertSame("{$home}/{$storage}", $resolved);
        }
    }

    public function testTheMissingEnvironmentFailsClosed(): void
    {
        // Config::fromArray maps a missing environment to Production (Environment::fromValue).
        $config = Config::fromArray(['database' => ['host' => 'h', 'name' => 'n', 'user' => 'u', 'password' => 'p'], 'paths' => ['public' => $this->tmp . '/home/public_html', 'storage' => $this->tmp . '/home/public_html/s']]);
        $this->expectException(RuntimeException::class);
        PublishFactory::locations($config, new Paths($this->tmp));
    }

    public function testDevelopmentBuildsLinkToTheDevStacksPort(): void
    {
        $config = static fn(mixed $port): Config => new Config(Environment::Development, new DatabaseConfig('db', 3306, 'n', 'u', 'p'), ['dev_port' => $port]);
        self::assertSame('http://fa.nova.localhost:8090', PublishFactory::buildConfig($config(8090))->hosts['fa']);
        self::assertSame('http://en.nova.localhost:8080', PublishFactory::buildConfig($config('x'))->hosts['en'], 'nonsense falls back to the default');
        self::assertSame('http://en.nova.localhost:8080', PublishFactory::buildConfig($config(70000))->hosts['en']);
    }

    public function testDevelopmentDefaultsAreSeparate(): void
    {
        [$public, $storage] = PublishFactory::locations($this->config(Environment::Development, []), new Paths($this->tmp));
        self::assertFalse(Files::isInside($storage, $public));
    }
}
