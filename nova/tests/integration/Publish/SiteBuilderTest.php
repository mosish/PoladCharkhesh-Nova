<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Publish;

use Nova\Core\Environment;
use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Dev\SampleCatalogue;
use Nova\Provenance\DesignationRef;
use Nova\Provenance\ValueInput;
use Nova\Publish\AssetBuilder;
use Nova\Publish\BuildConfig;
use Nova\Publish\SiteBuilder;
use Nova\Publish\SiteData;
use Nova\Tests\Integration\Catalogue\CatalogueFixture;
use PHPUnit\Framework\TestCase;

final class SiteBuilderTest extends TestCase
{
    private string $tmp;
    private string $assets;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nova-site-' . bin2hex(random_bytes(4));
        $this->assets = $this->tmp . '/assets';
        AssetBuilder::build(Paths::fromHere()->root . '/assets-src', $this->assets);
    }

    protected function tearDown(): void
    {
        Files::removeTree($this->tmp);
    }

    private function build(\PDO $pdo, BuildConfig $config): string
    {
        $out = $this->tmp . '/release';
        $root = Paths::fromHere()->root;
        $data = SiteData::load($pdo, $config->includeSamples, $root . '/app/i18n/contact.php');
        (new SiteBuilder($root . '/app', $this->assets, $config))->build($data, $out);

        return $out;
    }

    private static function production(): BuildConfig
    {
        return new BuildConfig(['fa' => 'https://fa.example.test', 'en' => 'https://en.example.test'], false, true);
    }

    public function testDevelopmentBuildOfTheSample(): void
    {
        $f = new CatalogueFixture();
        Sql::run($f->pdo, 'DELETE FROM spec_key');
        Sql::run($f->pdo, 'DELETE FROM family');
        SampleCatalogue::load($f->pdo, Environment::Development);
        $out = $this->build($f->pdo, BuildConfig::development());

        foreach (['fa', 'en'] as $lang) {
            foreach (['index.html', '404.html', 'privacy/index.html', 'sitemap.xml', 'robots.txt', 'p/6205/index.html', 'p/22220-e/index.html'] as $file) {
                self::assertFileExists("{$out}/{$lang}/{$file}");
            }
            $home = (string) file_get_contents("{$out}/{$lang}/index.html");
            self::assertStringContainsString('class="proto"', $home, 'samples are labelled in a development build');
            self::assertStringContainsString('<meta name="robots" content="noindex">', $home);
            self::assertSame(13, substr_count($home, '<tr data-slug='));
            self::assertStringContainsString('"suffix_rules":{}', $home, 'an empty map stays a JSON object');
            self::assertSame("User-agent: *\nDisallow: /\n", file_get_contents("{$out}/{$lang}/robots.txt"));
        }

        /** @var array{items: list<array{designation: string, values: list<mixed>, drawing: ?string}>} $json */
        $json = json_decode((string) file_get_contents("{$out}/data/p/6205.json"), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['6205', '6205-2RS', '6205-ZZ'], array_column($json['items'], 'designation'));
        self::assertCount(3, $json['items'][0]['values'], 'the base designation has its own values');
        self::assertSame([], $json['items'][1]['values'], 'nothing copied to the variant');
        self::assertNotNull($json['items'][0]['drawing']);

        /** @var array{files: array<string, array{size: int, sha256: string}>} $manifest */
        $manifest = json_decode((string) file_get_contents("{$out}/manifest.json"), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $file => $meta) {
            self::assertSame(hash_file('sha256', "{$out}/{$file}"), $meta['sha256'], $file);
        }
    }

    public function testProductionBuildsNeverContainSamples(): void
    {
        $f = new CatalogueFixture();
        Sql::run($f->pdo, 'DELETE FROM spec_key');
        Sql::run($f->pdo, 'DELETE FROM family');
        SampleCatalogue::load($f->pdo, Environment::Development);
        $out = $this->build($f->pdo, self::production());

        $home = (string) file_get_contents("{$out}/en/index.html");
        self::assertStringNotContainsString('class="proto"', $home);
        self::assertSame(0, substr_count($home, '<tr data-slug='));
        self::assertDirectoryDoesNotExist("{$out}/en/p");
        self::assertStringContainsString('<meta name="robots" content="index, follow">', $home);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://en.example.test/">', $home);
    }

    public function testDesignationsAndValuesAreEscapedEverywhere(): void
    {
        $f = new CatalogueFixture();
        $p = $f->products->createProduct($f->owner, $f->family, 'QZ <b onmouseover=x>&"', 'qz-hostile');
        Sql::run($f->pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id = ?", [$p]);
        $f->values->setValue($f->editor, DesignationRef::base($p), $f->keyNote, new ValueInput('<img src=x onerror=alert(1)>'));
        $out = $this->build($f->pdo, self::production());

        foreach (["{$out}/en/index.html", "{$out}/fa/p/qz-hostile/index.html"] as $file) {
            $html = (string) file_get_contents($file);
            self::assertStringNotContainsString('<b onmouseover', $html, $file);
            self::assertStringNotContainsString('<img src=x', $html, $file);
            self::assertStringContainsString('QZ &lt;b onmouseover=x&gt;&amp;&quot;', $html, $file);
        }
    }

    public function testExplorerCellsShowAValueOnlyWhenEveryListedItemAgrees(): void
    {
        $f = new CatalogueFixture();
        $p = $f->products->createProduct($f->owner, $f->family, 'QZ 1');
        Sql::run($f->pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id = ?", [$p]);
        $v = $f->products->addVariant($f->owner, $p, 'QZ 1 K', $f->source, true);
        Sql::run($f->pdo, "UPDATE product_variant SET status = 'published' WHERE id = ?", [$v]);
        $sourced = static fn(string $value): ValueInput => new ValueInput($value, 'pending', $f->source);
        foreach ([DesignationRef::base($p), DesignationRef::variant($v)] as $ref) {
            $f->values->setValue($f->editor, $ref, $f->keyD, $sourced('10'));                       // identical -> value
        }
        $f->values->setValue($f->editor, DesignationRef::base($p), $f->keyBigD, $sourced('30'));      // differs -> varies
        $f->values->setValue($f->editor, DesignationRef::variant($v), $f->keyBigD, $sourced('31'));

        $data = SiteData::load($f->pdo, false, Paths::fromHere()->root . '/app/i18n/contact.php');
        self::assertSame([
            ['k' => 'd', 'v' => '10', 'state' => 'value'],
            ['k' => 'D', 'v' => null, 'state' => 'varies'],
            ['k' => null, 'v' => null, 'state' => 'none'],
        ], $data->products[0]['cols']);
    }

    public function testStructuredDataOnlyForIndexablePages(): void
    {
        $f = new CatalogueFixture();
        $p = $f->products->createProduct($f->owner, $f->family, 'QZ 7');
        Sql::run($f->pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id = ?", [$p]);
        $out = $this->build($f->pdo, self::production());
        $pending = (string) file_get_contents("{$out}/en/p/qz-7/index.html");
        self::assertStringContainsString('<meta name="robots" content="noindex">', $pending, 'pending pages are noindex (D9/C1)');
        self::assertStringNotContainsString('"@type":"ProductGroup"', $pending);
        self::assertStringContainsString('"@type":"BreadcrumbList"', $pending);
        self::assertStringNotContainsString('/p/qz-7/', (string) file_get_contents("{$out}/en/sitemap.xml"));
    }
}
