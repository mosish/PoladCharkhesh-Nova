<?php

declare(strict_types=1);

namespace Nova\Security;

/** Admin roles (05 §14, decision C). */
enum Role: string
{
    case Owner = 'owner';
    case Editor = 'editor';
}
