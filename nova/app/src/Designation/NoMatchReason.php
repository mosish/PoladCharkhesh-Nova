<?php

declare(strict_types=1);

namespace Nova\Designation;

enum NoMatchReason: string
{
    case Empty = 'empty';
    case TooLong = 'too_long';
    case Unknown = 'unknown';
    case Ambiguous = 'ambiguous';
}
