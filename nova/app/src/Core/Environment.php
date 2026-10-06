<?php

declare(strict_types=1);

namespace Nova\Core;

/**
 * Where Nova runs. Fails closed: anything missing or unrecognised is
 * Production, so a development-only path can never open by accident.
 */
enum Environment: string
{
    case Development = 'development';
    case Staging = 'staging';
    case Production = 'production';

    public static function fromValue(mixed $value): self
    {
        return \is_string($value) ? (self::tryFrom($value) ?? self::Production) : self::Production;
    }
}
