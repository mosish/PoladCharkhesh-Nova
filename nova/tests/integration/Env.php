<?php

declare(strict_types=1);

namespace Nova\Tests\Integration;

use RuntimeException;

/** Reads the dev-stack environment set in compose.yaml. */
final class Env
{
    public static function get(string $name): string
    {
        $value = getenv($name);
        if (!\is_string($value) || $value === '') {
            throw new RuntimeException("{$name} is not set; run the integration tests inside the web container.");
        }

        return $value;
    }
}
