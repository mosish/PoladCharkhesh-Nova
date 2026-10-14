<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Content\ContentService;
use Nova\Content\RichText\Renderer;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Renders one release (05 §5) into a directory:
 *
 *   <out>/fa|en/index.html            the one-page site
 *   <out>/fa|en/p/<slug>/index.html   one page per published Product
 *   <out>/fa|en/privacy/index.html, 404.html, sitemap.xml, robots.txt
 *   <out>/data/p/<slug>.json          per-Product workspace data (both languages)
 *   <out>/designation-index.json      the matcher's index with the release id (not served; inquiry endpoint)
 *   <out>/manifest.json               every file with its size and sha256
 *
 * Content is in the HTML (works without JavaScript); Twig escapes by default.
 *
 * @phpstan-import-type Product from SiteData
 */
final class SiteBuilder
{
    private readonly Environment $twig;

    /** @var array<string, string> */
    private readonly array $assets;

    public function __construct(
        private readonly string $appDir,
        string $assetsDir,
        private readonly BuildConfig $config,
    ) {
        $this->assets = AssetBuilder::manifest($assetsDir);
        $this->twig = new Environment(new FilesystemLoader($appDir . '/templates/public'), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => false,
        ]);
        $this->twig->addFunction(new TwigFunction('asset', fn(string $name): string => $this->assets[$name] ?? throw new RuntimeException("Unknown asset {$name}")));
        $this->twig->addFilter(new TwigFilter('fill', static fn(string $tpl, array $vars): string => self::fill($tpl, $vars)));
        $this->twig->addFilter(new TwigFilter('iso', static fn(string $s): string => "\u{2066}{$s}\u{2069}"));
    }

    /**
     * @param ?string $release the release id; when given, `designation-index.json` records the index
     *                         with it for the inquiry endpoint (E-21, `Nova\Inquiry\LiveIndex`)
     *
     * @return list<string> files written, relative to `$out`
     */
    public function build(SiteData $data, string $out, ?string $release = null): array
    {
        if (is_dir($out) && (scandir($out) ?: []) !== ['.', '..']) {
            throw new RuntimeException("The release directory {$out} is not empty.");
        }
        $files = [];
        $slugs = [];
        foreach ($data->products as $p) {
            $slugs[$p['slug']] = true;
        }

        foreach (['fa', 'en'] as $lang) {
            $context = $this->context($data, $lang);
            /** @var array<string, mixed> $t */
            $t = $context['t'];
            $files[] = $this->write($out, "{$lang}/index.html", $this->twig->render('home.html.twig', ['home' => ''] + $context + [
                'canonical' => '/', 'robots' => $this->robots(true), 'page_title' => self::str($t['title'] ?? ''), 'page_desc' => self::str($t['desc'] ?? ''),
            ]));
            foreach ($data->products as $p) {
                $files[] = $this->write($out, "{$lang}/p/{$p['slug']}/index.html", $this->productPage($p, $lang, $context, $slugs));
            }
            $files[] = $this->write($out, "{$lang}/privacy/index.html", $this->twig->render('privacy.html.twig', $context + [
                'canonical' => '/privacy/', 'robots' => $this->robots(true), 'page_title' => self::str($t['pv_title'] ?? ''), 'page_desc' => self::str($t['pv_desc'] ?? ''),
            ] + $this->breadcrumbContext($lang, self::str($t['breadcrumb_home'] ?? ''), self::str($t['pv_h'] ?? ''), '/privacy/')));
            $files[] = $this->write($out, "{$lang}/404.html", $this->twig->render('404.html.twig', $context + [
                'canonical' => null, 'robots' => 'noindex', 'page_title' => self::str($t['nf_title'] ?? ''), 'page_desc' => self::str($t['nf_desc'] ?? ''),
            ]));
            $files[] = $this->write($out, "{$lang}/sitemap.xml", $this->sitemap($data, $lang));
            $files[] = $this->write($out, "{$lang}/robots.txt", $this->robotsTxt($lang));
            $files[] = $this->write($out, "{$lang}/llms.txt", Seo::llms($data, $this->config, $lang));
        }
        if ($release !== null) {
            // Not served (only fa/, en/ and data/ are); the endpoint reads it from the release directory.
            $files[] = $this->write($out, 'designation-index.json', json_encode(['release' => $release, 'index' => ['suffix_rules' => (object) $data->index['suffix_rules']] + $data->index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        foreach ($data->products as $p) {
            $files[] = $this->write($out, "data/p/{$p['slug']}.json", json_encode($this->productJson($p, $slugs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        $manifest = [];
        foreach ($files as $file) {
            $manifest[$file] = ['size' => (int) filesize("{$out}/{$file}"), 'sha256' => (string) hash_file('sha256', "{$out}/{$file}")];
        }
        ksort($manifest);
        $this->write($out, 'manifest.json', json_encode(['files' => $manifest, 'assets' => $this->assets], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $files;
    }

    /**
     * One Product page from the working state, for the Admin's draft preview
     * (05 §6): the same templates as a publish, always noindex, labelled as
     * a preview, written nowhere.
     */
    public function previewPage(SiteData $data, string $lang): ?string
    {
        $p = $data->products[0] ?? null;
        if ($p === null) {
            return null;
        }
        $slugs = [];
        foreach ($data->products as $product) {
            $slugs[$product['slug']] = true;
        }
        $context = ['preview' => true] + $this->context($data, $lang === 'en' ? 'en' : 'fa');

        return $this->productPage($p, $lang === 'en' ? 'en' : 'fa', $context, $slugs, true);
    }

    /**
     * Whether a Product page may be indexed: verified, at least one listed item, never a sample (D9/C1, 05 §17).
     * The Admin's outcome panel asks the same question of the working state.
     *
     * @param array{verified: bool, items: array<array-key, mixed>, is_sample: bool} $product
     */
    public static function indexable(array $product): bool
    {
        return $product['verified'] && $product['items'] !== [] && !$product['is_sample'];
    }

    /**
     * @param Product $p
     * @param array<string, mixed> $context
     * @param array<string, true> $slugs
     */
    private function productPage(array $p, string $lang, array $context, array $slugs, bool $preview = false): string
    {
        /** @var array<string, mixed> $t */
        $t = $context['t'];
        $code = $p['designation'];
        $family = $lang === 'fa' ? $p['family']['fa'] : $p['family']['en'];
        $items = array_map(static fn(array $i): string => $i['designation'], $p['items']);
        $variants = array_values(array_filter($items, static fn(string $item): bool => $item !== $code));
        $titleCode = $code . ($variants === [] ? '' : ' — ' . implode($lang === 'fa' ? '، ' : ', ', $variants));
        $desc = $items === [] ? self::fill(self::str($t['pp_desc'] ?? ''), ['code' => $code, 'family' => $family])
            : self::fill(self::str($t['pp_desc_items'] ?? ''), ['code' => $code, 'family' => $family, 'items' => implode($lang === 'fa' ? '، ' : ', ', $items)]);

        return $this->twig->render('product.html.twig', $context + [
            'p' => $p,
            'description_html' => $this->description($p, $lang, $slugs),
            'canonical' => "/p/{$p['slug']}/",
            'robots' => $preview ? 'noindex' : $this->robots(self::indexable($p)),
            'page_title' => self::fill(self::str($t['pp_title'] ?? ''), ['code' => $titleCode, 'family' => $family]),
            'page_desc' => $desc,
            'structured' => $this->structuredData($p, $lang),
        ] + $this->breadcrumbContext($lang, self::str($t['breadcrumb_home'] ?? ''), $code, "/p/{$p['slug']}/"));
    }

    /** @return array{breadcrumbs: list<array{name: string, url: string}>, breadcrumb_json: string} */
    private function breadcrumbContext(string $lang, string $home, string $name, string $path): array
    {
        $crumbs = [['name' => $home, 'url' => $this->config->url($lang, '/')], ['name' => $name, 'url' => $this->config->url($lang, $path)]];

        return ['breadcrumbs' => $crumbs, 'breadcrumb_json' => Seo::breadcrumbs($crumbs)];
    }

    /**
     * @param Product $p
     * @param array<string, true> $slugs
     */
    private function description(array $p, string $lang, array $slugs): ?string
    {
        $doc = $lang === 'fa' ? $p['description']['fa'] : $p['description']['en'];

        return $doc === null ? null : Renderer::render($doc, ContentService::PRODUCT_DESCRIPTION, new SiteRenderContext($lang, $slugs));
    }

    /** @return array<string, mixed> */
    private function context(SiteData $data, string $lang): array
    {
        $strings = Strings::load($this->appDir . '/i18n', $lang);
        $other = $lang === 'fa' ? 'en' : 'fa';
        $contact = $data->contact + $strings['contact_show'];
        $js = $strings['js'] + ['lang' => $lang, 'wa' => $contact['wa'] ?? '', 'tel' => $contact['tel1'] ?? ''];
        $families = [];
        foreach ($data->families as $f) {
            $families[$f['slug']] = $f;
        }

        return [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'other_lang' => $other,
            'subset' => $lang === 'fa' ? 'fa' : 'latin',
            't' => $strings['page'],
            'js' => $js,
            'home' => '/',
            'c' => $contact,
            'families' => $data->families,
            'fam' => $families,
            'products' => array_values(array_filter($data->products, static fn(array $p): bool => $p['show_in_explorer'])),
            'examples' => $data->examples,
            'dev_banner' => $data->samples,
            'self_url' => $this->config->hosts[$lang === 'fa' ? 'fa' : 'en'],
            'other_url' => $this->config->hosts[$other === 'fa' ? 'fa' : 'en'],
            'hosts' => $this->config->hosts,
            'steps_range' => [[0, .1], [.1, .3], [.3, .45], [.45, .72], [.72, .86], [.86, 1.01]],
            'bom_range' => array_map(static fn(int $i): array => [round(.45 + $i * .045, 3), round(.45 + ($i + 1) * .045, 3)], range(0, 5)),
            'js_strings' => self::json($js),
            'business_json' => Seo::business(self::str($strings['page']['brand'] ?? ''), $this->config->hosts[$lang === 'fa' ? 'fa' : 'en'], $this->config->hosts['en'], self::str($strings['page']['address'] ?? ''), $contact['land1'] ?? ''),
            // suffix_rules is a map: an empty one must stay a JSON object, never [] (the JS matcher rejects a list).
            'dindex' => self::json(['suffix_rules' => (object) $data->index['suffix_rules']] + $data->index),
        ];
    }

    /**
     * @param Product $p
     * @param array<string, true> $slugs
     *
     * @return array<string, mixed>
     */
    private function productJson(array $p, array $slugs): array
    {
        $f = $p['family'];

        return [
            'slug' => $p['slug'],
            'designation' => $p['designation'],
            'family' => ['slug' => $f['slug'], 'fa' => $f['fa'], 'en' => $f['en'], 'def_fa' => $f['def_fa'], 'def_en' => $f['def_en'], 'glyph' => $f['glyph']],
            'verified' => $p['verified'],
            'base_listed' => $p['base_listed'],
            'items' => $p['items'],
            'sources' => $p['sources'],
            'known_variants' => $p['known_variants'],
            'spellings' => $p['spellings'],
            'description' => ['fa' => $this->description($p, 'fa', $slugs), 'en' => $this->description($p, 'en', $slugs)],
        ];
    }

    /**
     * ProductGroup with hasVariant for listed variants; verified facts only, no Offer (05 §17).
     *
     * @param Product $p
     */
    private function structuredData(array $p, string $lang): ?string
    {
        if (!self::indexable($p)) {
            return null;
        }
        $variants = [];
        foreach ($p['items'] as $item) {
            if ($item['kind'] === 'variant') {
                $variants[] = ['@type' => 'Product', 'name' => $item['designation'], 'url' => $this->config->url($lang, "/p/{$p['slug']}/#{$item['anchor']}")];
            }
        }
        $data = ['@context' => 'https://schema.org', '@type' => 'ProductGroup', 'name' => $p['designation'], 'url' => $this->config->url($lang, "/p/{$p['slug']}/")];
        if ($variants !== []) {
            $data['hasVariant'] = $variants;
        }

        return self::json($data);
    }

    private function sitemap(SiteData $data, string $lang): string
    {
        $urls = [];
        if ($this->config->indexable) {
            $urls = ['/', '/privacy/'];
            foreach ($data->products as $p) {
                if (self::indexable($p)) {
                    $urls[] = "/p/{$p['slug']}/";
                }
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $path) {
            $xml .= '  <url><loc>' . htmlspecialchars($this->config->url($lang, $path), ENT_XML1) . "</loc></url>\n";
        }

        return $xml . "</urlset>\n";
    }

    private function robotsTxt(string $lang): string
    {
        return $this->config->indexable
            ? "User-agent: *\nAllow: /\n\nSitemap: " . $this->config->url($lang, '/sitemap.xml') . "\n"
            : "User-agent: *\nDisallow: /\n";
    }

    private function robots(bool $indexable): string
    {
        return $this->config->indexable && $indexable ? 'index, follow' : 'noindex';
    }

    private function write(string $out, string $file, string $content): string
    {
        $path = "{$out}/{$file}";
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0775, true) && !is_dir(\dirname($path))) {
            throw new RuntimeException("Cannot create {$path}");
        }
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException("Cannot write {$path}");
        }

        return $file;
    }

    /** @param array<array-key, mixed> $vars */
    public static function fill(string $tpl, array $vars): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($vars): string {
            $value = $vars[$m[1]] ?? null;

            return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : $m[0];
        }, $tpl);
    }

    /** JSON safe to embed in a <script type="application/json"> element. */
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
