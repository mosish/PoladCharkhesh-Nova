<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Admin;

use Nova\Admin\PublishAdmin;
use Nova\Audit\AuditLog;
use Nova\Catalogue\ProductChanges;
use Nova\Catalogue\ProductService;
use Nova\Core\Environment;
use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Publish\AssetBuilder;
use Nova\Publish\BuildConfig;
use Nova\Publish\Initiator;
use Nova\Publish\PublishEngine;
use Nova\Publish\PublishJournal;
use Nova\Publish\SiteBuilder;
use Nova\Publish\SymlinkSwitcher;
use Nova\Tests\Integration\Catalogue\CatalogueFixture;
use Nova\Tests\Integration\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * "Unpublished changes" (top bar, A1, A7) means changes the live release does
 * not contain: measured against the snapshot that release was built from,
 * not against the time it went live. Test data only; disposable database.
 */
final class UnpublishedChangesTest extends TestCase
{
    private static string $assets;
    private string $tmp;
    private CatalogueFixture $f;
    private int $product;
    private BuildConfig $config;
    private PublishAdmin $admin;

    public static function setUpBeforeClass(): void
    {
        self::$assets = sys_get_temp_dir() . '/nova-unpub-assets-' . bin2hex(random_bytes(4));
        AssetBuilder::build(Paths::fromHere()->root . '/assets-src', self::$assets);
    }

    public static function tearDownAfterClass(): void
    {
        Files::removeTree(self::$assets);
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nova-unpub-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/site', 0775, true);
        $this->f = new CatalogueFixture();
        $this->product = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1');
        Sql::run($this->f->pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id = ?", [$this->product]);
        $this->config = new BuildConfig(['fa' => 'https://fa.example.test', 'en' => 'https://en.example.test'], false, true);
        $this->admin = new PublishAdmin($this->f->pdo, $this->config, Environment::Production, Paths::fromHere()->root . '/app/i18n/contact.php');
    }

    protected function tearDown(): void
    {
        Files::removeTree($this->tmp);
    }

    private function engine(): PublishEngine
    {
        $root = Paths::fromHere()->root;

        return new PublishEngine($this->f->pdo, new SymlinkSwitcher($this->tmp . '/site'), new PublishJournal($this->tmp . '/journal.json'), new SiteBuilder($root . '/app', self::$assets, $this->config), $this->config, Environment::Production, $root . '/app/i18n/contact.php');
    }

    /** An editor's change on its own connection, as another Admin request would make it. */
    private function edit(string $showInExplorer): void
    {
        $pdo = TestDatabase::connect();
        $products = new ProductService($pdo, new AuditLog($pdo));
        $products->updateProduct($this->f->editor, $this->product, $this->f->version('product', $this->product), new ProductChanges(showInExplorer: $showInExplorer === '1'));
    }

    public function testAPublishClearsTheChangesItContains(): void
    {
        $this->engine()->publish(Initiator::cli('test'));
        self::assertSame(0, $this->admin->unpublishedCount());
        $this->edit('0');
        self::assertSame(1, $this->admin->unpublishedCount());
        $this->engine()->publish(Initiator::cli('test'));
        self::assertSame(0, $this->admin->unpublishedCount());
    }

    public function testAfterARollbackTheChangesNoLongerLiveAreUnpublishedAgain(): void
    {
        $this->engine()->publish(Initiator::cli('test'));
        $this->edit('0');
        $this->engine()->publish(Initiator::cli('test'));
        self::assertSame(0, $this->admin->unpublishedCount());

        $this->engine()->rollback(Initiator::cli('test'));
        self::assertSame(1, $this->admin->unpublishedCount(), 'the edit is in the rolled-back release only');
        self::assertSame(['product.update'], array_column($this->admin->changes(), 'action'));
    }

    public function testAnEditCommittedDuringABuildStaysUnpublished(): void
    {
        $this->engine()->publish(Initiator::cli('test'));
        $engine = $this->engine();
        // Another editor saves after the snapshot was taken, while the release is being built.
        $engine->injectFault(function (string $at): void {
            if ($at === 'built') {
                $this->edit('0');
            }
        });
        self::assertSame('live', $engine->publish(Initiator::cli('test'))->status);
        self::assertSame(1, $this->admin->unpublishedCount(), 'the live release was built before that edit');
    }

    public function testATransactionStillOpenAtTheSnapshotIsNotTreatedAsPublished(): void
    {
        $this->engine()->publish(Initiator::cli('test'));
        // An edit holds an audit id lower than the snapshot's newest id but commits only after the snapshot.
        $slow = TestDatabase::connect();
        $slow->beginTransaction();
        (new AuditLog($slow))->record($this->f->editor, 'product.update', 'product', $this->product, 'QZ 1', null, ['slow' => true]);
        $this->edit('0');                                   // a later id, committed before the snapshot
        $engine = $this->engine();
        $engine->injectFault(static function (string $at) use ($slow): void {
            if ($at === 'prepared') {                       // the snapshot has been read
                $slow->commit();
            }
        });
        self::assertSame('live', $engine->publish(Initiator::cli('test'))->status);
        self::assertSame(1, $this->admin->unpublishedCount(), 'the slow edit was not in the snapshot');
        self::assertSame(['product.update'], array_column($this->admin->changes(), 'action'));
    }
}
