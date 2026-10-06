<?php

declare(strict_types=1);

namespace Nova\Catalogue;

use RuntimeException;

/**
 * A refused catalogue change. `$reason` is stable and machine-readable; the
 * Admin maps it to Persian messages.
 */
final class CatalogueError extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function collision(string $designation, string $existing): self
    {
        return new self('designation_collision', "\"{$designation}\" has the same designation key as the existing \"{$existing}\".");
    }

    public static function stale(string $entity): self
    {
        return new self('stale', "The {$entity} was changed by someone else; reload and try again.");
    }

    public static function forbidden(string $what): self
    {
        return new self('forbidden', "Only the Owner can {$what}.");
    }

    public static function invalid(string $message): self
    {
        return new self('invalid', $message);
    }

    public static function notFound(string $entity): self
    {
        return new self('not_found', "The {$entity} does not exist.");
    }

    public static function conflict(string $message): self
    {
        return new self('conflict', $message);
    }
}
