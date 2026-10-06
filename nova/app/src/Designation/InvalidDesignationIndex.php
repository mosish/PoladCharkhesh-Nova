<?php

declare(strict_types=1);

namespace Nova\Designation;

use InvalidArgumentException;

/** The catalogue cannot be indexed; `$error` is the corpus error code. */
final class InvalidDesignationIndex extends InvalidArgumentException
{
    public function __construct(
        public readonly string $error,
        string $detail,
    ) {
        parent::__construct("{$error}: {$detail}");
    }
}
