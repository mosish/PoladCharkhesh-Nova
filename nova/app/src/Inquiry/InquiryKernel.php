<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Core\Config;
use Nova\Core\Database;
use Nova\Core\Environment;
use Nova\Core\Paths;
use Nova\Http\Request;
use Nova\Http\Response;
use Nova\Publish\PublishFactory;
use Nova\Publish\SystemClock;
use Throwable;

/** Builds the inquiry endpoint from configuration for public_html/api/inquiry.php. */
final class InquiryKernel
{
    public static function endpoint(Config $config, Paths $paths): InquiryEndpoint
    {
        [$public] = PublishFactory::locations($config, $paths);
        $build = PublishFactory::buildConfig($config);
        $settings = InquiryConfig::fromConfig($config);
        $pdo = null;
        $contact = [];
        foreach ((array) require $paths->root . '/app/i18n/contact.php' as $key => $value) {
            $contact[(string) $key] = \is_scalar($value) ? (string) $value : '';
        }
        $adminUrl = $build->hosts['fa'];

        return new InquiryEndpoint(
            $build,
            $settings,
            static function () use (&$pdo, $config): \PDO {
                return $pdo ??= Database::connect($config->database);
            },
            static fn(): ?array => (new LiveIndex(PublishFactory::switcher($config, $paths)))->load(),
            static fn(\PDO $pdo): InquiryNotifier => new InquiryNotifier($pdo, new SmtpMailer($settings->smtp, $settings->from), $adminUrl),
            new InquiryView($paths->root . '/app', $public . '/assets', $build, $contact, $config->environment === Environment::Development),
            new SystemClock(),
        );
    }

    public static function run(string $appRoot): void
    {
        // Errors go to the server log, never into a page (05 §15), whatever php.ini says.
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        try {
            $configFile = getenv('NOVA_CONFIG_FILE') ?: (getenv('HOME') ?: '/nonexistent') . '/config/config.php';
            $response = self::endpoint(Config::load($configFile), new Paths($appRoot))->handle(Request::fromGlobals());
        } catch (Throwable $e) {
            error_log('Nova inquiry bootstrap: ' . $e::class . ': ' . $e->getMessage());
            $response = new Response(503, 'The inquiry form is unavailable. Please call or use WhatsApp.', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        $response->send();
    }
}
