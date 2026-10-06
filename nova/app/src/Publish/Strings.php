<?php

declare(strict_types=1);

namespace Nova\Publish;

/**
 * Public UI strings: the verbatim Phase 2 port (i18n/phase2.<lang>.php) with
 * the production delta (i18n/public.<lang>.php) applied on top.
 */
final class Strings
{
    /** Strings that were trusted inline HTML (Markup) in the prototype; rendered unescaped. */
    public const array HTML_KEYS = ['dec', 'l10_h', 'l10_p'];

    /** @return array{page: array<string, mixed>, js: array<string, mixed>, contact_show: array<string, string>} */
    public static function load(string $i18nDir, string $lang): array
    {
        /** @var array{page: array<string, mixed>, js: array<string, mixed>, contact_show: array<string, string>} $base */
        $base = require "{$i18nDir}/phase2.{$lang}.php";
        /** @var array{page: array<string, mixed>, js: array<string, mixed>} $delta */
        $delta = require "{$i18nDir}/public.{$lang}.php";

        return [
            'page' => array_replace($base['page'], $delta['page']),
            'js' => array_replace($base['js'], $delta['js']),
            'contact_show' => $base['contact_show'],
        ];
    }
}
