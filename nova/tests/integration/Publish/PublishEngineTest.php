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
use Nova\Publish\Initiator;
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
    private Initiator $by;

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
        $this->by = Initiator::admin($this->f->owner);
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
        $result = $this->engine($s)->publish($this->by);

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
        $first = $this->engine($s)->publish($this->by);
        // An unconfirmed relationship claim: a published variant with no Owner confirmation.
        Sql::run($this->f->pdo, "INSERT INTO product_variant (product_id, designation, slug, listed, status) VALUES (?, 'QZ 1 X', 'qz-1-x', 1, 'published')", [$this->product]);
        // A rich-content link to a Product that is not published.
        $content = new ContentService($this->f->pdo, $this->f->audit);
        $content->setProductDescription($this->f->editor, $this->product, $this->f->version('product', $this->product), 'en', RichText::paragraph('See [another](product:not-published).'));

        $result = $this->engine($s)->publish($this->by);
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
        $result = $this->engine($this->switcher('symlink'))->publish($this->by);
        self::assertSame('failed', $result->status);
        self::assertStringContainsString('SAMPLE', implode("\n", $result->blockers));
    }

    public function testAVerifiedPageThatNoLongerQualifiesBlocks(): void
    {
        Sql::run($this->f->pdo, "UPDATE product SET verification = 'verified', verified_at = UTC_TIMESTAMP(), verified_by = ? WHERE id = ?", [$this->f->owner->userId, $this->product]);
        $result = $this->engine($this->switcher('symlink'))->publish($this->by);
        self::assertSame('failed', $result->status);
        self::assertStringContainsString('marked verified but', implode("\n", $result->blockers));
    }

    #[DataProvider('strategies')]
    public function testRollbackGoesBackAndThenFurtherBack(string $strategy): void
    {
        $s = $this->switcher($strategy);
        $engine = $this->engine($s);
        $r1 = $engine->publish($this->by)->release;
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        Sql::run($this->f->pdo, "UPDATE product SET designation = 'QZ 1 NEW' WHERE id = ?", [$this->product]);
        $r2 = $engine->publish($this->by)->release;
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        $r3 = $engine->publish($this->by)->release;

        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        self::assertSame($r2, $engine->rollback($this->by));
        self::assertSame($r2, $s->current());
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        self::assertSame($r1, $engine->rollback($this->by), 'a second rollback goes further back');
        self::assertStringNotContainsString('QZ 1 NEW', $this->liveHtml($s));
        $records = $this->records();
        self::assertSame(['live', 'rolled_back', 'rolled_back'], [$records[(string) $r1], $records[(string) $r2], $records[(string) $r3]]);
        self::assertContains('publish.rollback', $this->f->auditActions());
    }

    #[DataProvider('strategies')]
    public function testOnlyTheFiveMostRecentReleasesAreKept(string $strategy): void
    {
        $s = $this->switcher($strategy);
        $engine = $this->engine($s);
        $releases = [];
        for ($i = 0; $i < 7; ++$i) {
            $releases[] = (string) $engine->publish($this->by)->release;
            $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        }
        self::assertCount(7, array_unique($releases));
        $onDisk = array_map('basename', glob($this->tmp . ($strategy === 'symlink' ? '/site/releases/*' : '/archive/*')) ?: []);
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
            $this->engine($this->switcher('symlink'))->publish($this->by);
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
        $first = (string) $this->engine($s)->publish($this->by)->release;
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;

        $engine = $this->engine($s);
        $engine->injectFault(static function (string $at) use ($step): void {
            if ($at === $step) {
                throw new SimulatedCrash("killed after {$at}");
            }
        });
        try {
            $engine->publish($this->by);
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
        $this->engine($inner)->publish($this->by);
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        try {
            $this->engine(new CrashAfterActivate($inner))->publish($this->by);
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
        $engine->publish($this->by);
        $engine->publish($this->by);           // slot a was never live: no wait
        $result = $engine->publish($this->by); // slot b was live a moment ago
        self::assertSame('waiting', $result->status);
        self::assertSame(SlotSwitcher::GRACE_SECONDS, $result->waitSeconds);
    }

    /** @return array<string, mixed> the decoded after_text of the last audit event with this action */
    private function lastAudit(string $action): array
    {
        $json = Sql::scalar($this->f->pdo, 'SELECT after_text FROM audit_event WHERE action = ? ORDER BY id DESC LIMIT 1', [$action]);
        $data = json_decode((string) $json, true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Publishes two releases with the slot grace period between them.
     *
     * @return array{string, string} [first, second]
     */
    private function twoReleases(ReleaseSwitcher $s): array
    {
        $r1 = (string) $this->engine($s)->publish($this->by)->release;
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        $r2 = (string) $this->engine($s)->publish($this->by)->release;
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;

        return [$r1, $r2];
    }

    #[DataProvider('strategies')]
    public function testARollbackKilledAfterItsSwitchIsFinishedByRecovery(string $strategy): void
    {
        $s = $this->switcher($strategy);
        [$r1, $r2] = $this->twoReleases($s);
        try {
            $this->engine(new CrashAfterActivate($s))->rollback($this->by);
            self::fail('no crash');
        } catch (SimulatedCrash) {
        }
        self::assertSame($r1, $s->current(), 'the switch back happened');
        self::assertSame('live', $this->records()[$r2], 'not yet recorded');

        self::assertStringContainsString('Finished the interrupted rollback', (string) $this->engine($s)->recover());
        self::assertSame(['live', 'rolled_back'], [$this->records()[$r1], $this->records()[$r2]]);
        $audit = $this->lastAudit('publish.rollback');
        self::assertSame(['origin' => 'recovery'], $audit['by']);
        self::assertSame(['origin' => 'admin', 'user_id' => $this->f->owner->userId], $audit['started_by']);
        self::assertNull($this->engine($s)->recover(), 'recovery is idempotent');
        self::assertFileDoesNotExist($this->tmp . '/journal.json');
    }

    #[DataProvider('strategies')]
    public function testARollbackKilledBeforeItsSwitchChangesNothing(string $strategy): void
    {
        $s = $this->switcher($strategy);
        [$r1, $r2] = $this->twoReleases($s);
        $engine = $this->engine($s);
        $engine->injectFault(static function (string $at): void {
            if ($at === 'rollback-started') {
                throw new SimulatedCrash('killed before the switch');
            }
        });
        try {
            $engine->rollback($this->by);
            self::fail('no crash');
        } catch (SimulatedCrash) {
        }

        self::assertStringContainsString('never switched', (string) $this->engine($s)->recover());
        self::assertSame($r2, $s->current());
        self::assertSame(['superseded', 'live'], [$this->records()[$r1], $this->records()[$r2]]);
        self::assertNotContains('publish.rollback', $this->f->auditActions());
    }

    #[DataProvider('strategies')]
    public function testAFailedRollbackSwitchLeavesTheLiveReleaseAndNoJournal(string $strategy): void
    {
        $s = $this->switcher($strategy);
        [$r1, $r2] = $this->twoReleases($s);
        try {
            $this->engine(new FailingActivate($s))->rollback($this->by);
            self::fail('the switch did not fail');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Cannot switch', $e->getMessage());
        }
        self::assertSame($r2, $s->current());
        self::assertSame(['superseded', 'live'], [$this->records()[$r1], $this->records()[$r2]]);
        self::assertFileDoesNotExist($this->tmp . '/journal.json');
    }

    #[DataProvider('strategies')]
    public function testAFailedPublishSwitchLeavesThePreviousReleaseLive(string $strategy): void
    {
        $s = $this->switcher($strategy);
        $first = (string) $this->engine($s)->publish($this->by)->release;
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        try {
            $this->engine(new FailingActivate($s))->publish($this->by);
            self::fail('the switch did not fail');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Cannot switch', $e->getMessage());
        }
        $failed = (string) array_key_last($this->records());
        self::assertSame($first, $s->current());
        self::assertSame('failed', $this->records()[$failed]);
        self::assertFalse($s->has($failed), 'the unswitched build was removed');
        self::assertFileDoesNotExist($this->tmp . '/journal.json');
        self::assertNotSame('', $this->liveHtml($s));
    }

    /** Recovery records the release that went live, not the database as it is after the crash (finding 6). */
    #[DataProvider('strategies')]
    public function testRecoveryRecordsWhatTheSwitchedReleasePublishes(string $strategy): void
    {
        $s = $this->switcher($strategy);
        $this->engine($s)->publish($this->by);
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        $variant = TestDatabase::insert($this->f->pdo, 'product_variant', ['product_id' => $this->product, 'designation' => 'QZ 1 V', 'slug' => 'qz-1-v', 'listed' => 1, 'status' => 'published', 'relationship_source_id' => $this->f->source, 'relationship_confirmed_at' => gmdate('Y-m-d H:i:s'), 'relationship_confirmed_by' => $this->f->owner->userId]);
        $engine = $this->engine($s);
        $engine->injectFault(static function (string $at): void {
            if ($at === 'switched') {
                throw new SimulatedCrash('killed after the switch');
            }
        });
        try {
            $engine->publish($this->by);
            self::fail('no crash');
        } catch (SimulatedCrash) {
        }
        $crashed = (string) array_key_last($this->records());
        self::assertStringContainsString('qz-1-v', $this->liveHtml($s, 'en/p/qz-1/index.html'), 'the switched release lists the variant');

        // Between the crash and the recovery the catalogue moves on.
        $late = $this->f->products->createProduct($this->f->owner, $this->f->family, 'QZ 2');
        Sql::run($this->f->pdo, "UPDATE product SET status = 'published', base_listed = 1 WHERE id = ?", [$late]);
        Sql::run($this->f->pdo, "UPDATE product_variant SET status = 'draft' WHERE id = ?", [$variant]);

        $this->engine($s)->recover();
        self::assertSame('live', $this->records()[$crashed]);
        self::assertNull(Sql::scalar($this->f->pdo, 'SELECT first_published_at FROM product WHERE id = ?', [$late]), 'QZ 2 is not in the live release');
        self::assertNotNull(Sql::scalar($this->f->pdo, 'SELECT first_published_at FROM product_variant WHERE id = ?', [$variant]), 'the variant is in the live release');
        $summary = json_decode((string) Sql::scalar($this->f->pdo, 'SELECT summary_text FROM publish_record WHERE release_id = ?', [$crashed]), true);
        self::assertIsArray($summary);
        self::assertSame(1, $summary['products']);
        self::assertTrue($summary['recovered']);
        self::assertSame(['origin' => 'admin', 'user_id' => $this->f->owner->userId], $summary['started_by']);
        self::assertSame(['origin' => 'recovery'], $this->lastAudit('publish')['by']);
    }

    /** A recovery that itself dies after recording, before clearing the journal, does not record twice. */
    #[DataProvider('strategies')]
    public function testRepeatedRecoveryRecordsAndAuditsOnce(string $strategy): void
    {
        $s = $this->switcher($strategy);
        $this->engine($s)->publish($this->by);
        $this->clock->time += SlotSwitcher::GRACE_SECONDS;
        $engine = $this->engine($s);
        $engine->injectFault(static function (string $at): void {
            if ($at === 'switched') {
                throw new SimulatedCrash('killed after the switch');
            }
        });
        try {
            $engine->publish($this->by);
            self::fail('no crash');
        } catch (SimulatedCrash) {
        }
        $crashed = (string) array_key_last($this->records());
        $journal = (string) file_get_contents($this->tmp . '/journal.json');
        $this->engine($s)->recover();
        $wentLive = Sql::scalar($this->f->pdo, 'SELECT went_live_at FROM publish_record WHERE release_id = ?', [$crashed]);
        $audits = \count($this->f->auditActions());

        file_put_contents($this->tmp . '/journal.json', $journal);     // as if the journal was never cleared
        self::assertNotNull($this->engine($s)->recover());
        self::assertSame($wentLive, Sql::scalar($this->f->pdo, 'SELECT went_live_at FROM publish_record WHERE release_id = ?', [$crashed]));
        self::assertCount($audits, $this->f->auditActions(), 'no second audit entry');
        self::assertSame($crashed, $s->current());
        self::assertNull($this->engine($s)->recover());
    }

    public function testCommandLineAndRecoveryAreDistinguishedInTheAudit(): void
    {
        $s = $this->switcher('symlink');
        $cli = Initiator::cli('deploy');
        $first = (string) $this->engine($s)->publish($cli)->release;
        self::assertSame(['origin' => 'cli', 'os_user' => 'deploy'], $this->lastAudit('publish')['by']);
        self::assertNull(Sql::scalar($this->f->pdo, 'SELECT published_by FROM publish_record WHERE release_id = ?', [$first]), 'no Admin user');

        $this->engine($s)->publish($this->by);
        self::assertSame(['origin' => 'admin', 'user_id' => $this->f->owner->userId], $this->lastAudit('publish')['by']);
        $this->engine($s)->rollback($cli);
        self::assertSame(['origin' => 'cli', 'os_user' => 'deploy'], $this->lastAudit('publish.rollback')['by']);

        $engine = $this->engine($s);
        $engine->injectFault(static function (string $at): void {
            if ($at === 'switched') {
                throw new SimulatedCrash('killed');
            }
        });
        try {
            $engine->publish($cli);
        } catch (SimulatedCrash) {
        }
        $this->engine($s)->recover();
        $audit = $this->lastAudit('publish');
        self::assertSame(['origin' => 'recovery'], $audit['by']);
        self::assertSame(['origin' => 'cli', 'os_user' => 'deploy'], $audit['started_by']);
    }

    public function testAnOperatingSystemUserNameIsRecordedOnlyWhenPlain(): void
    {
        self::assertSame(['origin' => 'cli'], Initiator::cli("evil\nname")->toArray());
        self::assertSame(['origin' => 'cli'], Initiator::cli(null)->toArray());
        self::assertSame('cli', Initiator::currentProcess()->origin);
    }
}
