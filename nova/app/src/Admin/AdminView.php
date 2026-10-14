<?php

declare(strict_types=1);

namespace Nova\Admin;

use IntlDateFormatter;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Renders the Persian, RTL Admin (decision C) from app/templates/admin with
 * Twig auto-escaping. Codes, dimensions, units and release ids stay Latin;
 * ordinary counts use Persian digits; times are shown in Asia/Tehran on the
 * Persian calendar (stored in UTC).
 */
final class AdminView
{
    private readonly Environment $twig;

    /** @param array<string, string> $assets logical name => URL (public_html/admin/assets/manifest.json) */
    public function __construct(string $appDir, private readonly array $assets)
    {
        $this->twig = new Environment(new FilesystemLoader($appDir . '/templates/admin'), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => false,
        ]);
        $this->twig->addFunction(new TwigFunction('asset', fn(string $name): string => $this->assets[$name] ?? throw new RuntimeException("Unknown Admin asset {$name}")));
        $this->twig->addFilter(new TwigFilter('fa', self::digits(...)));
        $this->twig->addFilter(new TwigFilter('when', self::when(...)));
        $this->twig->addFilter(new TwigFilter('size', self::size(...)));
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context): string
    {
        return $this->twig->render($template, $context);
    }

    /** @return array<string, string> */
    public static function manifest(string $dir): array
    {
        $json = @file_get_contents($dir . '/manifest.json');
        if ($json === false) {
            throw new RuntimeException('Admin assets are not built (public_html/admin/assets/manifest.json missing); run app/bin/nova assets:build.');
        }
        /** @var array<string, string> */
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function digits(int|string|null $value): string
    {
        return strtr((string) $value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /** A byte count for people; units stay Latin (Q2). */
    public static function size(int|string|null $bytes): string
    {
        $n = (float) $bytes;
        if ($n < 1024) {
            return (int) $n . ' B';
        }
        if ($n < 1_048_576) {
            return round($n / 1024, 1) . ' KB';
        }

        return round($n / 1_048_576, 1) . ' MB';
    }

    /** A stored UTC DATETIME as a Tehran time on the Persian calendar, e.g. «۱۲ مهر ۱۴۰۵، ۱۵:۴۲». */
    public static function when(?string $utc, bool $withTime = true): string
    {
        if ($utc === null || $utc === '') {
            return '—';
        }
        $time = strtotime($utc . ' UTC');
        if ($time === false) {
            return '—';
        }
        $format = new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, $withTime ? 'd MMMM y، HH:mm' : 'd MMMM y');
        $text = $format->format($time);

        return \is_string($text) ? $text : '—';
    }
}
