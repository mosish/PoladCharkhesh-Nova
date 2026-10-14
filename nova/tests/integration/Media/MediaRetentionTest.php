<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Media;

use Nova\Core\Environment;
use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Media\DocumentService;
use Nova\Media\MediaLimits;
use Nova\Media\MediaService;
use Nova\Media\MediaStorage;
use Nova\Media\ReleaseMedia;
use Nova\Publish\AssetBuilder;
use Nova\Publish\BuildConfig;
use Nova\Publish\Initiator;
use Nova\Publish\PublishEngine;
use Nova\Publish\PublishJournal;
use Nova\Publish\SiteBuilder;
use Nova\Publish\SymlinkSwitcher;
use Nova\Tests\Integration\Catalogue\CatalogueFixture;
use Nova\Tests\Integration\Publish\TestClock;
use PHPUnit\Framework\TestCase;

/**
 * Replacement, trash and purge against draft content, the live release and
 * retained (rollback) releases (05 §8, §12): a file any retained release
 * links to is never deleted, however old; a file nothing needs is, once it
 * is 30 days old. Real releases are published into a temporary site.
 */
final class MediaRetentionTest extends TestCase
{
    private static string $assets;
    private string $tmp;
    private CatalogueFixture $f;
    private TestClock $clock;
    private MediaStorage $storage;
    private SymlinkSwitcher $switcher;
    private DocumentService $documents;
    private MediaService $media;
    private int $product;

    public static function setUpBeforeClass(): void
    {
        self::$assets = sys_get_temp_dir() . '/nova-ret-assets-' . bin2hex(random_bytes(4));
        AssetBuilder::build(Paths::fromHere()->root . '/assets-src', self::$assets);
    }

    public static function tearDownAfterClass(): void
    {
        Files::removeTree(self::$assets);
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nova-ret-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/site', 0775, true);
        $this->f = new CatalogueFixture();
        $this->clock = new TestClock(1_800_000_000);
        $this->storage = new MediaStorage($this->tmp . '/public');
        $this->switcher = new SymlinkSwitcher($this->tmp . '/site');
        $pdo = $this->f->pdo;
        $switcher = $this->switcher;
        $retained = static fn(): array => ReleaseMedia::referenced($pdo, $switcher);
        $limits = new MediaLimits();
        $this->documents = new DocumentService($pdo, $this->storage, $limits, $this->clock, $this->f->audit);
        $this->media = new MediaService($pdo, $this->storage, $limits, $this->clock, $this->f->audit, $retained);
        $this->product = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1');
        Sql::run($pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id = ?", [$this->product]);
    }

    protected function tearDown(): void
    {
        Files::removeTree($this->tmp);
    }

    private function publish(): string
    {
        $root = Paths::fromHere()->root;
        $config = new BuildConfig(['fa' => 'https://fa.example.test', 'en' => 'https://en.example.test'], false, true);
        $engine = new PublishEngine($this->f->pdo, $this->switcher, new PublishJournal($this->tmp . '/journal.json'), new SiteBuilder($root . '/app', self::$assets, $config), $config, Environment::Production, $root . '/app/i18n/contact.php');
        $r = $engine->publish(Initiator::admin($this->f->owner));
        self::assertSame('live', $r->status, implode('; ', $r->blockers));
        self::assertNotNull($r->release);
        $this->engine = $engine;

        return $r->release;
    }

    private PublishEngine $engine;

    private static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function purge(int $days): void
    {
        $this->clock->time += $days * 86_400;
        Sql::run($this->f->pdo, 'UPDATE document SET trashed_at = UTC_TIMESTAMP() - INTERVAL 40 DAY WHERE trashed_at IS NOT NULL');
        foreach (glob($this->tmp . '/public/media/*/*') ?: [] as $file) {
            touch($file, $this->clock->time - 40 * 86_400);
        }
        $this->documents->purgeTrash();
        $this->media->purgeTrash();
    }

    public function testAFileTheLiveOrARollbackReleaseLinksToSurvivesTheSweep(): void
    {
        $doc = $this->documents->upload($this->f->editor, 'ds.pdf', self::pdf(), 'برگه', 'Datasheet')['id'];
        $this->documents->link($this->f->editor, $doc, 'QZ 1', 'both');
        $first = $this->publish();
        $row = Sql::row($this->f->pdo, 'SELECT year, sha256 FROM document WHERE id = ?', [$doc]);
        self::assertNotNull($row);
        $file = $this->storage->path((int) $row['year'], (string) $row['sha256'], 'pdf');
        self::assertStringContainsString((string) $row['sha256'], (string) file_get_contents($this->tmp . "/site/releases/{$first}/en/p/qz-1/index.html"));

        // Draft content drops the document and deletes it; the live release still links to it.
        $link = (int) Sql::scalar($this->f->pdo, 'SELECT id FROM document_link WHERE document_id = ?', [$doc]);
        $this->documents->unlink($this->f->editor, $link);
        $this->documents->trash($this->f->editor, $doc);
        $this->purge(0);
        self::assertNull(Sql::scalar($this->f->pdo, 'SELECT id FROM document WHERE id = ?', [$doc]), 'the record is purged after 30 days in the trash');
        self::assertFileExists($file, 'the live release still links to the file');

        // A newer release no longer names it, but the first is retained for rollback.
        $second = $this->publish();
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString((string) $row['sha256'], (string) file_get_contents($this->tmp . "/site/releases/{$second}/en/p/qz-1/index.html"));
        $this->purge(40);
        self::assertFileExists($file, 'a retained rollback release still links to the file');
        $this->engine->rollback(Initiator::admin($this->f->owner));
        self::assertStringContainsString((string) $row['sha256'], (string) file_get_contents($this->tmp . "/site/current/en/p/qz-1/index.html"));
        self::assertFileExists($file, 'rolling back finds the file');

        // A newer publish moves on; once the first release is pruned, no retained release names the file and the sweep removes it.
        $third = $this->publish();
        self::assertNotSame($first, $third);
        $this->purge(40);
        self::assertFileExists($file, 'the first release is still retained');
        Sql::run($this->f->pdo, "UPDATE publish_record SET status = 'pruned' WHERE release_id = ?", [$first]);
        $this->switcher->discard($first);
        $this->purge(40);
        self::assertFileDoesNotExist($file, 'nothing needs it any more');
    }

    public function testASupersededImageFileIsKeptWhileAReleaseNamesItAndSweptAfterwards(): void
    {
        $im = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($im);
        $v1 = (string) ob_get_clean();
        $im2 = imagecreatetruecolor(50, 20);
        ob_start();
        imagepng($im2);
        $v2 = (string) ob_get_clean();

        $id = $this->media->upload($this->f->editor, 'a.png', $v1)['id'];
        $old = Sql::row($this->f->pdo, 'SELECT year, sha256 FROM media WHERE id = ?', [$id]);
        self::assertNotNull($old);
        $oldFile = $this->storage->path((int) $old['year'], (string) $old['sha256'], 'png');
        // A retained release that links to the image (figures are published through Article content in later steps).
        $release = $this->publish();
        file_put_contents($this->tmp . "/site/releases/{$release}/fa/extra.html", '<img src="/media/' . $old['year'] . '/' . $old['sha256'] . '.png">');

        $this->media->replace($this->f->editor, $id, 'b.png', $v2);
        $this->purge(40);
        self::assertFileExists($oldFile, 'a retained release names the superseded file');

        unlink($this->tmp . "/site/releases/{$release}/fa/extra.html");
        $this->purge(40);
        self::assertFileDoesNotExist($oldFile);
        $new = Sql::row($this->f->pdo, 'SELECT year, sha256 FROM media WHERE id = ?', [$id]);
        self::assertNotNull($new);
        self::assertFileExists($this->storage->path((int) $new['year'], (string) $new['sha256'], 'png'), 'the current file is never swept');
    }

    public function testTheSweepNeverRunsWithoutKnowledgeOfRetainedReleases(): void
    {
        $bare = new MediaService($this->f->pdo, $this->storage, new MediaLimits(), $this->clock, $this->f->audit);
        $this->storage->store(2027, str_repeat('a', 64), 'png', 'x');
        $path = $this->storage->path(2027, str_repeat('a', 64), 'png');
        touch($path, $this->clock->time - 90 * 86_400);
        self::assertSame(['items' => 0, 'files' => 0], $bare->purgeTrash());
        self::assertFileExists($path);
        self::assertSame(['items' => 0, 'files' => 1], $this->media->purgeTrash(), 'with the releases known, an unreferenced old orphan goes');
    }

    public function testReleaseScanFindsEveryMediaNameInHtmlAndJson(): void
    {
        $dir = $this->tmp . '/scan';
        mkdir($dir . '/data', 0775, true);
        $a = str_repeat('1', 64);
        $b = str_repeat('2', 64);
        file_put_contents($dir . '/index.html', "<a href=\"/media/2027/{$a}.pdf\">x</a>");
        file_put_contents($dir . '/data/p.json', "{\"srcset\":\"/media/2027/{$b}-480.webp 480w\"}");
        $found = [];
        ReleaseMedia::scan($dir, $found);
        self::assertSame([$a => true, $b => true], $found);
    }
}
