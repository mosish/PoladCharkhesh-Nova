<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Publish;

use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Publish\ReleaseSwitcher;
use Nova\Publish\SlotSwitcher;
use Nova\Publish\SymlinkSwitcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The docroot .htaccess on a real Apache (the dev image's 2.4, mod_rewrite as
 * on the production host): direct requests under /_site/ get 404 for both
 * strategies, while internal routing still serves the live release.
 *
 * A private Apache runs on a random local port with a temporary docroot that
 * holds a copy of public_html/.htaccess followed by a minimal stand-in for
 * the step 13 routing (one rewrite per language into the live release). The
 * stand-in is not the step 13 template; it shows the block rule leaves
 * internal rewrites working. Nothing in public_html/_site is touched.
 */
final class ApacheReleaseAccessTest extends TestCase
{
    private const string APACHE = '/usr/sbin/apache2';
    private const string OLD = 'R-20261006-100000-aaaa';
    private const string NEW = 'R-20261006-100100-bbbb';
    private const string THIRD = 'R-20261006-100200-cccc';

    private string $tmp;
    private int $port = 0;
    /** @var resource|null */
    private $process = null;

    protected function setUp(): void
    {
        if (!is_executable(self::APACHE)) {
            self::markTestSkipped('Apache is available only in the dev web container.');
        }
        $this->tmp = sys_get_temp_dir() . '/nova-apache-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/docroot/_site', 0755, true);
        chmod($this->tmp, 0755);
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $pid = @file_get_contents($this->tmp . '/httpd.pid');
        if (\is_string($pid) && (int) $pid > 0 && \function_exists('posix_kill')) {
            @posix_kill((int) $pid, 15);
        }
        Files::removeTree($this->tmp);
    }

    /** @return iterable<string, array{string}> */
    public static function layouts(): iterable
    {
        yield 'S symlink' => ['symlink'];
        yield 'D slots, slot a live' => ['slots-a'];
        yield 'D slots, slot b live (ACTIVE_B)' => ['slots-b'];
    }

    #[DataProvider('layouts')]
    public function testDirectRequestsUnderSiteAre404AndTheLiveReleaseIsServed(string $layout): void
    {
        $live = $this->buildLayout($layout);
        $this->startApache($layout === 'symlink' ? 'current' : null);

        foreach (['en.example.test' => 'en', 'fa.example.test' => 'fa'] as $host => $lang) {
            [$status, $body] = $this->get('/', $host);
            self::assertSame(200, $status, "home on {$host}");
            self::assertStringContainsString("{$lang} home {$live}", $body);
            [$status, $body] = $this->get('/p/x/', $host);
            self::assertSame(200, $status);
            self::assertStringContainsString("{$lang} product {$live}", $body);
        }

        $direct = [
            '/_site', '/_site/', '/_site/manifest.json',
            '/_site/current/', '/_site/current/en/', '/_site/current/en/index.html', '/_site/current/manifest.json',
            '/_site/releases/', '/_site/releases/' . self::OLD . '/en/index.html', '/_site/releases/' . self::NEW . '/manifest.json',
            '/_site/slot-a/en/index.html', '/_site/slot-b/en/index.html', '/_site/slot-a/manifest.json', '/_site/ACTIVE_B',
            '/%5fsite/current/en/index.html', '/%5Fsite/slot-a/en/index.html', '//_site/current/en/index.html', '/_SITE/current/en/index.html',
            '/./_site/current/en/index.html', '/x/../_site/slot-b/en/index.html',
        ];
        foreach ($direct as $path) {
            [$status, $body] = $this->get($path, 'en.example.test');
            self::assertSame(404, $status, "{$path} must be 404");
            self::assertStringNotContainsString(' home R-', $body, "{$path} leaked a release");
        }

        // Release-root files are not under a language folder, so routing never reaches them either.
        foreach (['/manifest.json', '/.release', '/en/index.html', '/..%2fmanifest.json'] as $path) {
            [$status, $body] = $this->get($path, 'en.example.test');
            self::assertNotSame(200, $status, "{$path} must not be served");
            self::assertStringNotContainsString('"files"', $body);
        }
    }

    /** Builds the site with the real switchers; returns the live release id. */
    private function buildLayout(string $layout): string
    {
        $site = $this->tmp . '/docroot/_site';
        $clock = new TestClock();
        $switcher = $layout === 'symlink' ? new SymlinkSwitcher($site) : new SlotSwitcher($site, $this->tmp . '/archive', $clock);
        $ids = $layout === 'slots-b' ? [self::OLD, self::NEW, self::THIRD] : [self::OLD, self::NEW];
        foreach ($ids as $id) {
            $this->release($switcher, $id);
            $switcher->activate($id);
            $clock->time += SlotSwitcher::GRACE_SECONDS;
        }
        if ($switcher instanceof SlotSwitcher) {
            self::assertSame($layout === 'slots-b' ? 'b' : 'a', $switcher->activeSlot());
        }

        return (string) $switcher->current();
    }

    private function release(ReleaseSwitcher $s, string $id): void
    {
        $dir = $s->prepare($id);
        foreach (['en', 'fa'] as $lang) {
            mkdir("{$dir}/{$lang}/p/x", 0755, true);
            file_put_contents("{$dir}/{$lang}/index.html", "<p>{$lang} home {$id}</p>");
            file_put_contents("{$dir}/{$lang}/p/x/index.html", "<p>{$lang} product {$id}</p>");
        }
        file_put_contents("{$dir}/manifest.json", '{"files":{"en/index.html":{}}}');
    }

    /** @param ?string $current 'current' for strategy S; null selects slots by the ACTIVE_B marker (strategy D) */
    private function startApache(?string $current): void
    {
        $docroot = $this->tmp . '/docroot';
        $htaccess = (string) file_get_contents(Paths::fromHere()->root . '/public_html/.htaccess');
        $route = static function (string $lang, string $hostCond, string $target): string {
            return "RewriteCond %{ENV:REDIRECT_STATUS} ^$\n{$hostCond}RewriteRule ^(.*)$ _site/{$target}/{$lang}/\$1 [L]\n";
        };
        $routing = "\n# --- test stand-in for the step 13 routing ---\nRewriteEngine On\n";
        foreach (['en' => "RewriteCond %{HTTP_HOST} ^en\\. [NC]\n", 'fa' => ''] as $lang => $cond) {
            if ($current !== null) {
                $routing .= $route($lang, $cond, $current);
            } else {
                $routing .= $route($lang, $cond . "RewriteCond %{DOCUMENT_ROOT}/_site/ACTIVE_B -f\n", 'slot-b');
                $routing .= $route($lang, $cond, 'slot-a');
            }
        }
        file_put_contents($docroot . '/.htaccess', $htaccess . $routing);

        $this->port = random_int(20000, 39999);
        $modules = '/usr/lib/apache2/modules';
        $conf = <<<CONF
            ServerRoot "{$this->tmp}"
            ServerName localhost
            LoadModule mpm_prefork_module {$modules}/mod_mpm_prefork.so
            LoadModule authz_core_module {$modules}/mod_authz_core.so
            LoadModule rewrite_module {$modules}/mod_rewrite.so
            LoadModule headers_module {$modules}/mod_headers.so
            LoadModule dir_module {$modules}/mod_dir.so
            LoadModule mime_module {$modules}/mod_mime.so
            User www-data
            Group www-data
            Listen 127.0.0.1:{$this->port}
            PidFile "{$this->tmp}/httpd.pid"
            ErrorLog "{$this->tmp}/error.log"
            LogLevel warn
            TypesConfig /etc/mime.types
            DirectoryIndex index.html
            DocumentRoot "{$docroot}"
            <Directory />
                AllowOverride None
                Require all denied
            </Directory>
            <Directory "{$docroot}">
                Options Indexes FollowSymLinks
                AllowOverride All
                Require all granted
            </Directory>
            CONF;
        file_put_contents($this->tmp . '/httpd.conf', $conf);

        // setsid: prefork signals its whole process group on shutdown, which must not include PHPUnit.
        $process = proc_open(['setsid', self::APACHE, '-f', $this->tmp . '/httpd.conf', '-DFOREGROUND'], [1 => ['file', $this->tmp . '/stdout.log', 'a'], 2 => ['file', $this->tmp . '/stderr.log', 'a']], $pipes);
        if (!\is_resource($process)) {
            throw new RuntimeException('Cannot start Apache.');
        }
        $this->process = $process;
        for ($i = 0; $i < 100; ++$i) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }

        throw new RuntimeException('Apache did not start: ' . @file_get_contents($this->tmp . '/stderr.log') . @file_get_contents($this->tmp . '/error.log'));
    }

    /** @return array{int, string} status, body */
    private function get(string $path, string $host): array
    {
        $socket = fsockopen('127.0.0.1', $this->port, $errno, $errstr, 2.0);
        if ($socket === false) {
            throw new RuntimeException("connect: {$errstr}");
        }
        fwrite($socket, "GET {$path} HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $response = (string) stream_get_contents($socket);
        fclose($socket);
        [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');

        return [preg_match('#^HTTP/1\.[01] (\d{3})#', $head, $m) === 1 ? (int) $m[1] : 0, $body];
    }
}
