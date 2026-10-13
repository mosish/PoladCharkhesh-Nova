<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Publish;

use Nova\Core\Files;
use Nova\Publish\ReleaseSwitcher;
use Nova\Publish\SimulatedCrash;
use Nova\Publish\SlotSwitcher;
use Nova\Publish\SymlinkSwitcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SwitcherTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/nova-switch-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/site', 0775, true);
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

    private function switcher(string $strategy, ?TestClock $clock = null): ReleaseSwitcher
    {
        return $strategy === 'symlink'
            ? new SymlinkSwitcher($this->tmp . '/site')
            : new SlotSwitcher($this->tmp . '/site', $this->tmp . '/archive', $clock ?? new TestClock());
    }

    private function release(ReleaseSwitcher $s, string $id, string $content): void
    {
        $dir = $s->prepare($id);
        mkdir("{$dir}/fa", 0775, true);
        file_put_contents("{$dir}/fa/index.html", $content);
    }

    /** What a visitor would be served right now. */
    private function live(ReleaseSwitcher $s): string
    {
        $base = $this->tmp . '/site/';
        $path = $s instanceof SlotSwitcher ? $base . 'slot-' . $s->activeSlot() . '/fa/index.html' : $base . 'current/fa/index.html';

        return (string) @file_get_contents($path);
    }

    #[DataProvider('strategies')]
    public function testActivateSwitchesWhatVisitorsSee(string $strategy): void
    {
        $clock = new TestClock();
        $s = $this->switcher($strategy, $clock);
        self::assertNull($s->current());

        $this->release($s, 'R-20261006-100000-aaaa', 'one');
        $s->activate('R-20261006-100000-aaaa');
        self::assertSame('R-20261006-100000-aaaa', $s->current());
        self::assertSame('one', $this->live($s));

        $clock->time += SlotSwitcher::GRACE_SECONDS;
        $this->release($s, 'R-20261006-100100-bbbb', 'two');
        $s->activate('R-20261006-100100-bbbb');
        self::assertSame('R-20261006-100100-bbbb', $s->current());
        self::assertSame('two', $this->live($s));

        // Back to the first one (what rollback does).
        $clock->time += SlotSwitcher::GRACE_SECONDS;
        $s->activate('R-20261006-100000-aaaa');
        self::assertSame('one', $this->live($s));
        self::assertTrue($s->has('R-20261006-100100-bbbb'));
    }

    #[DataProvider('strategies')]
    public function testTheLiveReleaseCannotBeDiscarded(string $strategy): void
    {
        $s = $this->switcher($strategy);
        $this->release($s, 'R-20261006-100000-aaaa', 'one');
        $s->activate('R-20261006-100000-aaaa');

        $this->expectException(RuntimeException::class);
        $s->discard('R-20261006-100000-aaaa');
    }

    public function testSymlinkSwapIsARenameOverTheLink(): void
    {
        $s = new SymlinkSwitcher($this->tmp . '/site');
        $this->release($s, 'R-20261006-100000-aaaa', 'one');
        $s->activate('R-20261006-100000-aaaa');
        self::assertTrue(is_link($this->tmp . '/site/current'));
        self::assertSame('releases/R-20261006-100000-aaaa', readlink($this->tmp . '/site/current'));
        self::assertSame([], glob($this->tmp . '/site/current.tmp-*') ?: [], 'no temporary link left behind');
    }

    public function testASlotIsReusedOnlyAfterTheGracePeriod(): void
    {
        $clock = new TestClock();
        $s = new SlotSwitcher($this->tmp . '/site', $this->tmp . '/archive', $clock);
        foreach (['R-20261006-100000-aaaa', 'R-20261006-100100-bbbb', 'R-20261006-100200-cccc'] as $id) {
            $this->release($s, $id, $id);
        }
        $s->activate('R-20261006-100000-aaaa');               // into slot b (slot a was never live)
        $s->activate('R-20261006-100100-bbbb');               // into slot a, which was never live
        self::assertSame(SlotSwitcher::GRACE_SECONDS, $s->waitBefore('R-20261006-100200-cccc'), 'slot b was live a moment ago');

        try {
            $s->activate('R-20261006-100200-cccc');
            self::fail('a slot was reused inside its grace period');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('120 s', $e->getMessage());
        }
        self::assertSame('R-20261006-100100-bbbb', $s->current(), 'the live release is untouched');

        $clock->time += SlotSwitcher::GRACE_SECONDS;
        $s->activate('R-20261006-100200-cccc');
        self::assertSame('R-20261006-100200-cccc', $s->current());
        self::assertSame('b', $s->activeSlot());
    }

    /** @return iterable<string, array{string}> */
    public static function slotCrashPoints(): iterable
    {
        yield 'after the copy' => ['copied'];
        yield 'after the grace timestamp, before the marker' => ['graced'];
        yield 'after the marker, before the final timestamp' => ['marked'];
    }

    /**
     * Wherever activate() is killed, the slot that was live within the grace
     * period is never handed out for immediate reuse (finding 5).
     */
    #[DataProvider('slotCrashPoints')]
    public function testAKilledSlotSwitchNeverExposesAJustLiveSlotToReuse(string $step): void
    {
        $clock = new TestClock();
        $trap = new class {
            public bool $armed = false;
        };
        $fault = static function (string $at) use ($trap, $step): void {
            if ($trap->armed && $at === $step) {
                throw new SimulatedCrash("killed after {$at}");
            }
        };
        $s = new SlotSwitcher($this->tmp . '/site', $this->tmp . '/archive', $clock, $fault);
        foreach (['R-20261006-100000-aaaa', 'R-20261006-100100-bbbb', 'R-20261006-100200-cccc', 'R-20261006-100300-dddd'] as $id) {
            $this->release($s, $id, $id);
        }
        $s->activate('R-20261006-100000-aaaa');                 // slot b
        $s->activate('R-20261006-100100-bbbb');                 // slot a
        $clock->time += SlotSwitcher::GRACE_SECONDS;
        $trap->armed = true;
        try {
            $s->activate('R-20261006-100200-cccc');             // into slot b, killed part-way
            self::fail('no crash');
        } catch (SimulatedCrash) {
        }
        $trap->armed = false;

        $switched = $step === 'marked';
        self::assertSame($switched ? 'R-20261006-100200-cccc' : 'R-20261006-100100-bbbb', $s->current());
        self::assertSame($switched ? 'R-20261006-100200-cccc' : 'R-20261006-100100-bbbb', $this->live($s), 'visitors get a complete release');
        $wait = $s->waitBefore('R-20261006-100300-dddd');
        if ($switched) {
            self::assertSame(SlotSwitcher::GRACE_SECONDS, $wait, 'slot a was live until the crash');
        } else {
            self::assertSame(0, $wait, 'slot b was not live within the grace period; it may be rebuilt');
        }
        $clock->time += SlotSwitcher::GRACE_SECONDS;
        $s->activate('R-20261006-100300-dddd');
        self::assertSame('R-20261006-100300-dddd', $this->live($s));
    }

    public function testSlotMarkersAndTimestampsLeaveNoTemporaryFiles(): void
    {
        $clock = new TestClock();
        $s = new SlotSwitcher($this->tmp . '/site', $this->tmp . '/archive', $clock);
        foreach (['R-20261006-100000-aaaa', 'R-20261006-100100-bbbb', 'R-20261006-100200-cccc'] as $id) {
            $this->release($s, $id, $id);
            $s->activate($id);
            $clock->time += SlotSwitcher::GRACE_SECONDS;
        }
        self::assertSame("R-20261006-100200-cccc\n", file_get_contents($this->tmp . '/site/ACTIVE_B'));
        self::assertSame([], glob($this->tmp . '/site/*.tmp-*') ?: []);
        self::assertSame([], glob($this->tmp . '/site/.*.tmp-*') ?: []);
    }

    /**
     * The link is relative, so it resolves wherever the tree is mounted
     * (container path, Windows bind mount, or the host's home directory).
     */
    public function testTheSymlinkStaysValidWhenTheTreeMoves(): void
    {
        $s = new SymlinkSwitcher($this->tmp . '/site');
        $this->release($s, 'R-20261006-100000-aaaa', 'one');
        $s->activate('R-20261006-100000-aaaa');
        rename($this->tmp . '/site', $this->tmp . '/moved');

        $moved = new SymlinkSwitcher($this->tmp . '/moved');
        self::assertSame('R-20261006-100000-aaaa', $moved->current());
        self::assertSame('one', file_get_contents($this->tmp . '/moved/current/fa/index.html'));
        rename($this->tmp . '/moved', $this->tmp . '/site');
    }

    /** @return iterable<string, array{string}> */
    public static function foreignLinks(): iterable
    {
        yield 'absolute host path (Windows tool re-created it)' => ['/mnt/host/c/Users/x/nova/public_html/_site/releases/R-20261006-100000-aaaa'];
        yield 'relative but dangling' => ['releases/R-20261006-100900-ffff'];
        yield 'not a release' => ['../../etc'];
    }

    /** A link Nova did not write is refused, never read as "nothing is live" (which would misdirect rollback and pruning). */
    #[DataProvider('foreignLinks')]
    public function testAForeignOrDanglingCurrentLinkIsRefused(string $target): void
    {
        $s = new SymlinkSwitcher($this->tmp . '/site');
        $this->release($s, 'R-20261006-100000-aaaa', 'one');
        symlink($target, $this->tmp . '/site/current');

        try {
            $s->current();
            self::fail('a foreign link was accepted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not the relative release link', $e->getMessage());
        }
        // Re-pointing it the way Nova writes it repairs it.
        unlink($this->tmp . '/site/current');
        $s->activate('R-20261006-100000-aaaa');
        self::assertSame('R-20261006-100000-aaaa', $s->current());
    }

    public function testAPlainFolderInPlaceOfTheLinkIsRefused(): void
    {
        $s = new SymlinkSwitcher($this->tmp . '/site');
        mkdir($this->tmp . '/site/current');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a symlink');
        $s->current();
    }
}
