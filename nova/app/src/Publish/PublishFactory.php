<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Core\Config;
use Nova\Core\Environment;
use Nova\Core\Paths;
use PDO;
use RuntimeException;

/**
 * Builds the Publish Engine from configuration:
 * - `release_strategy`: 'symlink' (S) or 'slots' (D), chosen from the hosting
 *   probe (E-20); the dev stack uses S.
 * - `paths.public` / `paths.storage`: the document root and Nova's storage
 *   (outside the docroot on a server, 05 §1).
 * - `hosts.fa` / `hosts.en`: the two origins (D6); development uses the
 *   fa./en.nova.localhost dev hosts.
 */
final class PublishFactory
{
    public static function engine(Config $config, Paths $paths, PDO $pdo): PublishEngine
    {
        $public = self::path($config, 'public', $paths->root . '/public_html');
        $storage = self::path($config, 'storage', $paths->root . '/storage');
        $switcher = match ($config->get('release_strategy', 'symlink')) {
            'symlink' => new SymlinkSwitcher($public . '/_site'),
            'slots' => new SlotSwitcher($public . '/_site', $storage . '/releases'),
            default => throw new RuntimeException('release_strategy must be "symlink" or "slots".'),
        };
        $build = self::buildConfig($config);

        return new PublishEngine(
            $pdo,
            $switcher,
            new PublishJournal($storage . '/publish/journal.json'),
            new SiteBuilder($paths->root . '/app', $public . '/assets', $build),
            $build,
            $config->environment,
            $paths->root . '/app/i18n/contact.php',
        );
    }

    public static function buildConfig(Config $config): BuildConfig
    {
        if ($config->environment === Environment::Development) {
            return BuildConfig::development();
        }
        $hosts = $config->get('hosts');
        if (!\is_array($hosts) || !\is_string($hosts['fa'] ?? null) || !\is_string($hosts['en'] ?? null)) {
            throw new RuntimeException('Configuration needs hosts.fa and hosts.en (absolute origins).');
        }

        // Only production is indexable; staging is noindex throughout (05 §22).
        return new BuildConfig(['fa' => rtrim($hosts['fa'], '/'), 'en' => rtrim($hosts['en'], '/')], false, $config->environment === Environment::Production);
    }

    private static function path(Config $config, string $key, string $default): string
    {
        $paths = $config->get('paths');
        $value = \is_array($paths) ? ($paths[$key] ?? null) : null;

        return \is_string($value) && $value !== '' ? rtrim($value, '/') : $default;
    }
}
