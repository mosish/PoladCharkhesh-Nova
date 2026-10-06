<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Publish;

use Nova\Content\ContentService;
use Nova\Content\RichText\RichText;
use Nova\Core\Environment;
use Nova\Core\Files;
use Nova\Core\Paths;
use Nova\Core\Sql;
use Nova\Publish\AssetBuilder;
use Nova\Publish\BuildConfig;
use Nova\Publish\PublishEngine;
use Nova\Publish\PublishJournal;
use Nova\Publish\ReleaseSwitcher;
use Nova\Publish\SimulatedCrash;
use Nova\Publish\SiteBuilder;
use Nova\Publish\SlotSwitcher;
use Nova\Publish\SymlinkSwitcher;
use Nova\Tests\Integration\Catalogue\CatalogueFixture;
use Nova\Tests\Integration\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PublishEngineTest extends TestCase
{
    private static string $assets;
    private string $tmp;
    private CatalogueFixture $f;
    private TestClock $clock;
    private int $product;

    public static function setUpBeforeClass(): void
    {
        self::$assets = sys_get_temp_dir() . '/nova-pub-assets-' . bin2hex(random_bytes(4));
        AssetBuilder::build(Paths::fromHere()->root . '/assets-src', self::$assets);
    }

    public static function tearDownAfterClass(): void
    {
        Files::removeTree(self::$assets);
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nova-pub-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/site', 0775, true);
        $this->f = new CatalogueFixture();
        $this->clock = new TestClock();
        $this->product = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 1');
        Sql::run($this->f->pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id = ?", [$this->product]);
    }

    protected function tearDown(): void
    {
        Files::removeTree($this->tmp);
    }

    /** @return iterable<string, array{string}> */
    public static function strategies(): iterable
    {
        yield 'S symlink' => ['symlink'];
        yield 'D slots' => ['slots'];
    }

    private function switcher(string $strategy): ReleaseSwitcher
    {
        return $strategy === 'symlink' ? new SymlinkSwitcher($this->tmp . '/site') : new SlotSwitcher($this->tmp . '/site', $this->tmp . '/archive', $this->clock);
    }

    private function engine(ReleaseSwitcher $switcher, Environment $env = Environment::Production): PublishEngine
    {
        $root = Paths::fromHere()->root;
        $config = new BuildConfig(['fa' => 'https://fa.example.test', 'en' => 'https://en.example.test'], $env === Environment::Development, $env === Environment::Production);

        return new PublishEngine($this->f->pdo, $switcher, new PublishJournal($this->tmp . '/journal.json'), new SiteBuilder($root . '/app', self::$assets, $config), $config, $env, $root . '/app/i18n/contact.php');
    }

    /** @return array<array-key, string> release => status */
    private function records(): array
    {
        return array_map(strval(...), Sql::pairs($this->f->pdo, 'SELECT release_id, status FROM publish_record ORDER BY id'));
    }

    private function liveHtml(ReleaseSwitcher $s, string $file = 'en/index.html'): string
    {
        $dir = $s instanceof SymlinkSwitcher ? $this->tmp . '/site/current' : $this->tmp . '/site/slot-' . ($s instanceof SlotSwitcher ? $s->activeSlot() : 'a');

        return (string) @file_get_contents("{$dir}/{$file}");
    }

    #[DataProvider('strategies')]
    public function testPublishBuildsVerifiesSwitchesAndRecords(string $strategy): void
    {
        $s = $this->switcher($strategy);
        $result = $this->engine($s)->publish($this->f->owner);

        self::assertSame('live', $result->status, implode('; ', $result->blockers));
        self::assertSame($result->release, $s->current());
        self::assertStringContainsString('QZ 1', $this->liveHtml($s, 'en/p/qz-1/index.html'));
        self::assertSame([$result->release => 'live'], $this->records());
        self::assertNotNull(Sql::scalar($this->f->pdo, 'SELECT first_published_at FROM product WHERE id = ?', [$this->product]), 'public identity is now frozen');
        self::assertContains('publish', $this->f->auditActions());
        self::assertFileDoesNotExist($this->tmp . '/journal.json');
    }

    public function testBlockersStopThePublishAndLeaveTheLiveSiteAlone(): void
    {
        $s = $this->switcher('symlink');
        $first = $this->engine($s)->publish($this->f->owner);
        // An unconfirmed relationship claim: a published variant with no Owner confirmation.
        Sql::run($this->f->pdo, "INSERT INTO product_variant (product_id, designation, slug, listed, status) VALUES (?, 'QZ 1 X', 'qz-1-x', 1, 'published')", [$this->product]);
        // A rich-content link to a Product that is not published.
        $content = new ContentService($this->f->pdo, $this->f->audit);
        $content->setProductDescription($this->f->editor, $this->product, $this->f->version('product', $this->product), 'en', RichText::paragraph('See [another](product:not-published).'));

        $result = $this->engine($s)->publish($this->f->owner);
        self::assertSame('failed', $result->status);
        self::assertStringContainsString('QZ 1 X', implode("\n", $result->blockers));
        self::assertStringContainsString('product:not-published', implode("\n", $result->blockers));
        self::assertSame($first->release, $s->current());
        self::assertSame('failed', $this->records()[$result->release]);
        self::assertSame([], glob($this->tmp . '/site/releases/' . $result->release) ?: [], 'nothing was built');
    }

    public function testSamplesAreRefusedOutsideDevelopment(): void
    {
        Sql::run($this->f->pdo, 'UPDATE product SET is_sample = 1 WHERE id = ?', [$this->product]);
        $result = $this->engine($this->switcher('symlink'))->publish($this->f->owner);
        self::assertSame('failed', $result->status);
        self::assertStringContainsString('SAMPLE', implode("\n", $result->blockers));
    }

    public function testAVerifiedPageThatNoLongerQualifiesBlocks(): void
    {
        Sql::run($this->f->pdo, "UPDATE product SET verification = 'verified', verified_at = UTC_TIMESTAMP(), verified_by = ? WHERE id = ?", [$this->f->owner->userId, $this->product]);
        $result = $this->engine($this->switcher('symlink'))->publish($this->f->owner);
        self::assertSame('failed', $result->status);
        self::assertStringContainsString('marked verified but', implode("\n", $result->blockers));
    }

    #[DataProvider('strategies')]
    public function testRollbackGoesBackAndThenFurtherBack(string $strategy): void
    {
        $s = $this->switcher($strategy);
        $engine = $this->engine($s);
        $r1 = $engine->publish($this->f->owner)->release;
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        Sql::run($this->f->pdo, "UPDATE product SET designation = 'QZ 1 NEW' WHERE id = ?", [$this->product]);
        $r2 = $engine->publish($this->f->owner)->release;
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        $r3 = $engine->publish($this->f->owner)->release;

        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        self::assertSame($r2, $engine->rollback($this->f->owner));
        self::assertSame($r2, $s->current());
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        self::assertSame($r1, $engine->rollback($this->f->owner), 'a second rollback goes further back');
        self::assertStringNotContainsString('QZ 1 NEW', $this->liveHtml($s));
        $records = $this->records();
        self::assertSame(['live', 'rolled_back', 'rolled_back'], [$records[(string) $r1], $records[(string) $r2], $records[(string) $r3]]);
        self::assertContains('publish.rollback', $this->f->auditActions());
    }

    public function testOnlyTheFiveMostRecentReleasesAreKept(): void
    {
        $s = $this->switcher('symlink');
        $engine = $this->engine($s);
        $releases = [];
        for ($i = 0; $i < 7; ++$i) {
            $releases[] = (string) $engine->publish($this->f->owner)->release;
        }
        self::assertCount(7, array_unique($releases));
        $onDisk = array_map('basename', glob($this->tmp . '/site/releases/*') ?: []);
        self::assertCount(PublishEngine::KEEP, $onDisk);
        self::assertContains($s->current(), $onDisk);
        self::assertSame(2, \count(array_filter($this->records(), static fn(string $st): bool => $st === 'pruned')));
    }

    public function testOnlyOnePublishAtATime(): void
    {
        $holder = TestDatabase::connect();
        Sql::run($holder, "SELECT GET_LOCK('nova_publish', 0)");
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('in progress');
            $this->engine($this->switcher('symlink'))->publish($this->f->owner);
        } finally {
            Sql::run($holder, "SELECT RELEASE_LOCK('nova_publish')");
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function crashPoints(): iterable
    {
        foreach (['symlink', 'slots'] as $strategy) {
            foreach (['started', 'prepared', 'built', 'verified', 'switched', 'recorded'] as $step) {
                yield "{$strategy} after {$step}" => [$strategy, $step];
            }
        }
    }

    /** A process killed after any journal step leaves a consistent site once recover() runs. */
    #[DataProvider('crashPoints')]
    public function testRecoveryAfterACrashAtEveryStep(string $strategy, string $step): void
    {
        $s = $this->switcher($strategy);
        $first = (string) $this->engine($s)->publish($this->f->owner)->release;
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;

        $engine = $this->engine($s);
        $engine->injectFault(static function (string $at) use ($step): void {
            if ($at === $step) {
                throw new SimulatedCrash("killed after {$at}");
            }
        });
        try {
            $engine->publish($this->f->owner);
            self::fail('the fault did not fire');
        } catch (SimulatedCrash) {
        }
        $crashed = (string) array_key_last($this->records());

        $message = $this->engine($s)->recover();
        $switched = \in_array($step, ['switched', 'recorded'], true);
        self::assertNotNull($message);
        self::assertFileDoesNotExist($this->tmp . '/journal.json');
        if ($switched) {
            self::assertSame($crashed, $s->current(), 'the switched release stays live');
            self::assertSame('live', $this->records()[$crashed]);
            self::assertSame('superseded', $this->records()[$first]);
        } else {
            self::assertSame($first, $s->current(), 'the previous release is still live');
            self::assertSame('failed', $this->records()[$crashed]);
            self::assertFalse($s->has($crashed), 'the partial build was removed');
        }
        self::assertNotSame('', $this->liveHtml($s), 'visitors always get a complete release');
        self::assertNull($this->engine($s)->recover(), 'recovery is idempotent');
    }

    #[DataProvider('strategies')]
    public function testACrashBetweenTheSwitchAndItsJournalLineIsFinishedNotUndone(string $strategy): void
    {
        $inner = $this->switcher($strategy);
        $this->engine($inner)->publish($this->f->owner);
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        try {
            $this->engine(new CrashAfterActivate($inner))->publish($this->f->owner);
            self::fail('no crash');
        } catch (SimulatedCrash) {
        }
        $crashed = (string) array_key_last($this->records());
        self::assertSame($crashed, $inner->current(), 'the switch happened');

        $this->engine($inner)->recover();
        self::assertSame('live', $this->records()[$crashed]);
        self::assertSame($crashed, $inner->current());
    }

    public function testSlotGracePeriodMakesAPublishWait(): void
    {
        $s = $this->switcher('slots');
        $engine = $this->engine($s);
        $engine->publish($this->f->owner);
        $engine->publish($this->f->owner);           // slot a was never live: no wait
        $result = $engine->publish($this->f->owner); // slot b was live a moment ago
        self::assertSame('waiting', $result->status);
        self::assertSame(SlotSwitcher::GRACE_SECONDS, $result->waitSeconds);
    }
}
