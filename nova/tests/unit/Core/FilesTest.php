<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Core;

use Nova\Core\Files;
use PHPUnit\Framework\TestCase;

final class FilesTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/nova-files-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/a/b', 0775, true);
    }

    protected function tearDown(): void
    {
        Files::removeTree($this->tmp);
    }

    public function testResolveFollowsSymlinksBeforeDotDot(): void
    {
        mkdir($this->tmp . '/x/y', 0775, true);
        symlink($this->tmp . '/x/y', $this->tmp . '/a/link');
        // The OS resolves link/.. to x, not a: a textual collapse would say a/b.
        self::assertSame($this->tmp . '/x/b', Files::resolve($this->tmp . '/a/link/../b'));
        self::assertSame($this->tmp . '/x/y/new/file', Files::resolve($this->tmp . '/a/link/new/./file'));
        self::assertSame($this->tmp . '/a', Files::resolve($this->tmp . '/a/b/missing/../..'));
    }

    public function testIsInside(): void
    {
        self::assertTrue(Files::isInside($this->tmp . '/a/b', $this->tmp . '/a'));
        self::assertTrue(Files::isInside($this->tmp . '/a', $this->tmp . '/a/'));
        self::assertFalse(Files::isInside($this->tmp . '/ab', $this->tmp . '/a'));
        self::assertFalse(Files::isInside($this->tmp . '/a/../c', $this->tmp . '/a'));
    }

    public function testWriteAtomicReplacesAndLeavesNoTemporaryFile(): void
    {
        Files::writeAtomic($this->tmp . '/f', 'one');
        Files::writeAtomic($this->tmp . '/f', 'two');
        self::assertSame('two', file_get_contents($this->tmp . '/f'));
        self::assertSame([], glob($this->tmp . '/f.tmp-*') ?: []);
    }
}
