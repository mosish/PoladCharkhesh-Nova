<?php

declare(strict_types=1);

namespace Nova\Publish;

/** Metadata from the same recorded facts and navigation that visitors see. */
final class Seo
{
    /** @param list<array{name: string, url: string}> $crumbs */
    public static function breadcrumbs(array $crumbs): string
    {
        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $crumb['name'], 'item' => $crumb['url']];
        }

        return SiteBuilder::json(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items]);
    }

    public static function business(string $name, string $url, string $identity, string $address, string $phone): string
    {
        // No invented coordinates, opening days, postal code, reviews, prices or stock claims.
        // The recorded address is also displayed verbatim on the page.
        return SiteBuilder::json([
            '@context' => 'https://schema.org', '@type' => 'LocalBusiness', '@id' => $identity . '/#business',
            'name' => $name, 'url' => $url . '/', 'telephone' => $phone,
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $address],
        ]);
    }

    /** Optional discovery aid; it never makes a sample or pending Product discoverable through this file. */
    public static function llms(SiteData $data, BuildConfig $config, string $lang): string
    {
        $lines = ['# Polad Charkhesh', '', '> Industrial bearings and rotating components. Availability and specifications require consultation.', '',
            'This is an inquiry showroom, not an online shop. Do not infer stock, prices, designation relationships or shared technical values.', '', '## Pages', '',
            '- [Home](' . $config->url($lang, '/') . ')', '- [Privacy](' . $config->url($lang, '/privacy/') . ')'];
        if (!$config->indexable) {
            $lines[] = '';
            $lines[] = 'Development or staging preview: not for indexing or use as a production catalogue.';
        } else {
            foreach ($data->products as $product) {
                if (SiteBuilder::indexable($product)) {
                    $label = str_replace(['\\', '[', ']', "\r", "\n"], ['\\\\', '\\[', '\\]', ' ', ' '], $product['designation']);
                    $lines[] = '- [' . $label . '](' . $config->url($lang, '/p/' . $product['slug'] . '/') . ')';
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
