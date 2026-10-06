<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Designation;

use InvalidArgumentException;
use Nova\Designation\DesignationIndex;
use Nova\Designation\Matcher;
use Nova\Designation\Normaliser;
use PHPUnit\Framework\TestCase;

/** Server-only rule: invalid UTF-8 is rejected, never normalised or matched. */
final class Utf8Test extends TestCase
{
    public function testNormaliserRejectsInvalidUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Normaliser::key("6205\xC3");
    }

    public function testMatcherRejectsInvalidUtf8(): void
    {
        $matcher = new Matcher(DesignationIndex::fromArray(['products' => [], 'spellings' => [], 'suffix_rules' => []]));

        $this->expectException(InvalidArgumentException::class);
        $matcher->match("\xFF6205");
    }
}
