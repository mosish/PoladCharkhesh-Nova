<?php

declare(strict_types=1);

namespace Nova\Tests\Integration\Backup;

use Nova\Tests\Integration\TestDatabase;

/** The test database's own name and password, for assertions that they are (or are not) where they should be. */
final class TestDatabaseSecrets
{
    public static function name(): string
    {
        return TestDatabase::config()->name;
    }

    public static function password(): string
    {
        return TestDatabase::config()->password;
    }
}
