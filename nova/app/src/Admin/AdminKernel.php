<?php

declare(strict_types=1);

namespace Nova\Admin;

use Nova\Core\Config;
use Nova\Core\Database;
use Nova\Core\Environment;
use Nova\Core\Paths;
use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Publish\PublishFactory;
use Nova\Publish\SiteBuilder;
use Nova\Publish\SiteData;
use Nova\Security\AdminAuth;
use Nova\Security\NativeSessionStore;
use Throwable;

/**
 * Builds the Admin from configuration for public_html/admin/index.php.
 * The Admin host is `admin_host` when configured, else the Persian host
 * (D11: .ir only); development uses fa.nova.localhost.
 */
final class AdminKernel
{
    public static function app(Config $config, Paths $paths): AdminApp
    {
        $pdo = Database::connect($config->database);
        [$public, $storage] = PublishFactory::locations($config, $paths);
        $build = PublishFactory::buildConfig($config);
        $contact = $paths->root . '/app/i18n/contact.php';
        $siteBuilder = static fn(): SiteBuilder => new SiteBuilder($paths->root . '/app', $public . '/assets', $build);

        return new AdminApp(
            $pdo,
            new NativeSessionStore($storage . '/sessions', AdminAuth::ABSOLUTE),
            new AdminView($paths->root . '/app', AdminView::manifest($public . '/admin/assets')),
            self::adminHost($config, $build->hosts['fa']),
            static fn() => PublishFactory::engine($config, $paths, $pdo),
            new PublishAdmin($pdo, $build, $config->environment, $contact),
            static function (int $id, string $lang) use ($pdo, $build, $contact, $siteBuilder): ?string {
                $data = SiteData::preview($pdo, $id, $build->includeSamples, $contact);

                return $data === null ? null : $siteBuilder()->previewPage($data, $lang);
            },
            $build->hosts['fa'] . '/',
        );
    }

    public static function run(string $appRoot): void
    {
        // Errors go to the server log, never into a page (05 §15), whatever php.ini says.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        try {
            $configFile = getenv('NOVA_CONFIG_FILE') ?: (getenv('HOME') ?: '/nonexistent') . '/config/config.php';
            $config = Config::load($configFile);
            $request = Request::fromGlobals();
            // Any other host gets a plain 404 before the database or the session is touched (D11).
            if ($request->hostname() !== self::adminHost($config, PublishFactory::buildConfig($config)->hosts['fa'])) {
                (new Response(404, 'Not found', ['Content-Type' => 'text/plain; charset=utf-8']))->send();

                return;
            }
            $response = self::app($config, new Paths($appRoot))->handle($request);
        } catch (Throwable $e) {
            error_log('Nova Admin bootstrap: ' . $e::class . ': ' . $e->getMessage());
            $response = new Response(500, 'The Admin is unavailable.', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $response->send();
    }

    public static function adminHost(Config $config, string $faOrigin): string
    {
        $configured = $config->get('admin_host');
        if (\is_string($configured) && $configured !== '') {
            return strtolower($configured);
        }
        $host = parse_url($faOrigin, PHP_URL_HOST);
        if ($config->environment !== Environment::Development && \is_string($host)) {
            return strtolower($host);
        }

        return 'fa.nova.localhost';
    }
}
