<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Publish;

use Nova\Core\Files;
use Nova\Publish\ReleaseSwitcher;
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
}
