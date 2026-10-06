<?php

declare(strict_types=1);

namespace Nova\Publish;

use RuntimeException;

/**
 * Builds content-hashed public assets from assets-src/ into public_html/assets/
 * (05 §5): one stylesheet (E-6: the frozen nova.css plus production additions,
 * concatenated, not minified), the ES modules (import specifiers rewritten to
 * hashed names), and the self-hosted fonts. Runs on the workstation or at
 * packaging, never on the server. Hashed files are never deleted here: older
 * releases may still reference them.
 */
final class AssetBuilder
{
    public const array CSS = ['css/nova.css', 'css/nova-production.css'];

    /** JS modules in dependency order (a module may import only modules listed before it). */
    public const array JS = ['js/designation.js', 'js/ws.js', 'js/nova.js', 'js/product-page.js', 'js/admin.js'];

    /** @return array<string, string> logical name => public path */
    public static function build(string $sourceDir, string $outDir): array
    {
        if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new RuntimeException("Cannot create {$outDir}");
        }
        $manifest = [];

        foreach (glob("{$sourceDir}/fonts/*.woff2") ?: [] as $font) {
            $manifest['fonts/' . basename($font)] = self::write($outDir, 'fonts/' . basename($font), (string) file_get_contents($font));
        }

        $css = '';
        foreach (self::CSS as $file) {
            $css .= "/* {$file} */\n" . self::read("{$sourceDir}/{$file}") . "\n";
        }
        $manifest['nova.css'] = self::write($outDir, 'nova.css', $css);

        foreach (self::JS as $file) {
            if (!is_file("{$sourceDir}/{$file}")) {
                continue;
            }
            $code = self::read("{$sourceDir}/{$file}");
            // Rewrite './name.js' specifiers of modules built earlier to their hashed names.
            $code = (string) preg_replace_callback("/(['\"`])\\.\\/([a-z0-9-]+\\.js)/", static function (array $m) use ($manifest): string {
                $target = $manifest[$m[2]] ?? null;
                if ($target === null) {
                    throw new RuntimeException("Unknown or later module ./{$m[2]}");
                }

                return $m[1] . './' . basename($target);
            }, $code);
            $manifest[basename($file)] = self::write($outDir, basename($file), $code);
        }

        ksort($manifest);
        file_put_contents("{$outDir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        return $manifest;
    }

    /** @return array<string, string> */
    public static function manifest(string $outDir): array
    {
        $json = @file_get_contents("{$outDir}/manifest.json");
        if ($json === false) {
            throw new RuntimeException('Assets are not built (public_html/assets/manifest.json missing); run app/bin/nova assets:build.');
        }
        /** @var array<string, string> */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private static function write(string $outDir, string $logical, string $bytes): string
    {
        $info = pathinfo($logical);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';
        $name = $dir . $info['filename'] . '.' . substr(hash('sha256', $bytes), 0, 12) . '.' . ($info['extension'] ?? '');
        $path = "{$outDir}/{$name}";
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0775, true);
        }
        if (!is_file($path)) {
            file_put_contents($path, $bytes);
        }

        return '/assets/' . $name;
    }

    private static function read(string $path): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        return $bytes;
    }
}
