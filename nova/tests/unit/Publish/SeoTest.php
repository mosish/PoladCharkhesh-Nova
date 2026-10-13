<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Publish;

use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Publish\AssetBuilder;
use Nova\Publish\BuildConfig;
use Nova\Publish\Seo;
use Nova\Publish\SeoAudit;
use Nova\Publish\SiteBuilder;
use Nova\Publish\SiteData;
use Nova\Publish\Validator;
use PHPUnit\Framework\TestCase;

final class SeoTest extends TestCase
{
    public function testRenderedDevelopmentAndProductionPagesPassWithoutADatabase(): void
    {
        $root = Paths::fromHere()->root;
        $tmp = sys_get_temp_dir() . '/nova-seo-' . bin2hex(random_bytes(8));
        /** @var array<string, string> $contact */
        $contact = require $root . '/app/i18n/contact.php';
        $data = new SiteData([], [], ['products' => [], 'spellings' => [], 'suffix_rules' => []], $contact, [], false);
        try {
            $assets = AssetBuilder::build($root . '/assets-src', $tmp . '/assets');
            self::assertFileExists($tmp . $assets['favicon.svg']);
            foreach ([BuildConfig::development(), new BuildConfig(['fa' => 'https://fa.example.test', 'en' => 'https://en.example.test'], false, true)] as $i => $config) {
                $out = $tmp . '/release-' . $i;
                (new SiteBuilder($root . '/app', $tmp . '/assets', $config))->build($data, $out);
                self::assertSame([], Validator::checkOutput($out, $config));
                foreach (['fa', 'en'] as $lang) {
                    $home = (string) file_get_contents($out . '/' . $lang . '/index.html');
                    self::assertStringContainsString($assets['favicon.svg'], $home);
                    self::assertStringContainsString('"@type":"LocalBusiness"', $home);
                    $error = (string) file_get_contents($out . '/' . $lang . '/404.html');
                    self::assertStringNotContainsString('rel="canonical"', $error);
                    self::assertStringContainsString('content="noindex"', $error);
                    $xml = new \DOMDocument();
                    self::assertTrue($xml->load($out . '/' . $lang . '/sitemap.xml', LIBXML_NONET));
                    self::assertSame($config->indexable ? 2 : 0, $xml->getElementsByTagName('loc')->length);
                    $robots = (string) file_get_contents($out . '/' . $lang . '/robots.txt');
                    self::assertStringContainsString($config->indexable ? $config->url($lang, '/sitemap.xml') : 'Disallow: /', $robots);
                    self::assertFileExists($out . '/' . $lang . '/llms.txt');
                }
            }
        } finally {
            Files::removeTree($tmp);
        }
    }

    public function testStructuredDataEscapesUntrustedTextAndDoesNotInventBusinessFacts(): void
    {
        $json = Seo::business('Shop </script><script>alert(1)</script>', 'https://en.example.test', 'https://en.example.test', 'Recorded address', '+982100000000');
        self::assertStringNotContainsString('</script>', $json);
        /** @var array{address: array{streetAddress: string}, '@id': string} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Recorded address', $data['address']['streetAddress']);
        self::assertArrayNotHasKey('openingHoursSpecification', $data);
        self::assertArrayNotHasKey('aggregateRating', $data);
        self::assertArrayNotHasKey('geo', $data);
        self::assertSame('https://en.example.test/#business', $data['@id']);
    }

    public function testBreadcrumbsKeepTheirOrderAndPersianText(): void
    {
        $json = Seo::breadcrumbs([['name' => 'خانه', 'url' => 'https://fa.example.test/'], ['name' => '6205', 'url' => 'https://fa.example.test/p/6205/']]);
        /** @var array{itemListElement: list<array{position: int, name: string}>} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([1, 2], array_column($data['itemListElement'], 'position'));
        self::assertSame('خانه', $data['itemListElement'][0]['name']);
    }

    public function testMetadataAndLinksPassForACompleteBilingualSite(): void
    {
        self::assertSame([], SeoAudit::pages($this->pages(), BuildConfig::development()));
    }

    public function testBrokenMetadataLinksAndImagesBlockTheOutput(): void
    {
        $pages = $this->pages();
        $pages['en/index.html'] = str_replace('<h1>Home en</h1>', '<h2>Home en</h2><img src="/x.png"><a href="/missing/">Bad</a><a href="#absent">Bad anchor</a><script type="application/ld+json">{</script><p>Phase 5</p>', $pages['en/index.html']);
        $pages['en/index.html'] = str_replace('http://en.nova.localhost:8080/"', 'http://wrong.test/"', $pages['en/index.html']);
        $errors = implode("\n", SeoAudit::pages($pages, BuildConfig::development()));
        foreach (['nonempty h1', 'alt attribute', 'broken internal link', 'missing anchor', 'malformed structured-data', 'placeholder text', 'canonical'] as $expected) {
            self::assertStringContainsString($expected, $errors);
        }
    }

    public function testDuplicateTitlesAndHeadingsAreCaughtWithinALanguage(): void
    {
        $pages = $this->pages();
        $pages['en/privacy/index.html'] = str_replace('Privacy en', 'Home en', $pages['en/privacy/index.html']);
        $errors = implode("\n", SeoAudit::pages($pages, BuildConfig::development()));
        self::assertStringContainsString('duplicate title', $errors);
        self::assertStringContainsString('duplicate main heading', $errors);
    }

    public function testDevelopmentDiscoveryFileDoesNotListSampleProducts(): void
    {
        $data = new SiteData([], [], ['products' => [], 'spellings' => [], 'suffix_rules' => []], [], [], true);
        $text = Seo::llms($data, BuildConfig::development(), 'fa');
        self::assertStringContainsString('not for indexing', $text);
        self::assertStringNotContainsString('/p/', $text);
    }

    public function testProductionOriginsCannotAccidentallyPublishLocalOrCredentialUrls(): void
    {
        foreach (['http://example.com', 'https://127.0.0.1', 'https://[::1]', 'https://fa.nova.localhost', 'https://FA.NOVA.LOCALHOST.', 'https://user:pass@example.com', 'https://example.com/path', 'https://example.com?x=1'] as $bad) {
            try {
                new BuildConfig(['fa' => $bad, 'en' => 'https://en.example.test'], false, true);
                self::fail('Unsafe origin accepted: ' . $bad);
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new BuildConfig(['fa' => 'https://fa.example.test', 'en' => 'https://en.example.test'], true, true);
    }

    /** @return array<string, string> */
    private function pages(): array
    {
        $pages = [];
        $config = BuildConfig::development();
        foreach (['fa', 'en'] as $lang) {
            foreach (['' => 'Home', 'privacy/' => 'Privacy'] as $path => $name) {
                $head = '<title>' . $name . ' ' . $lang . '</title><meta name="description" content="Useful description"><meta name="robots" content="noindex"><link rel="canonical" href="' . $config->url($lang, '/' . $path) . '">';
                foreach (['fa' => 'fa', 'en' => 'en', 'x-default' => 'en'] as $alternate => $target) {
                    $head .= '<link rel="alternate" hreflang="' . $alternate . '" href="' . $config->url($target, '/' . $path) . '">';
                }
                $pages[$lang . '/' . $path . 'index.html'] = '<!doctype html><html><head>' . $head . '</head><body><h1>' . $name . ' ' . $lang . '</h1><a href="/privacy/">Privacy</a><a href="/">Home</a><img src="/decor.svg" alt=""></body></html>';
            }
        }

        return $pages;
    }
}
