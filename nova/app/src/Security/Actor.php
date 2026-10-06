<?php

declare(strict_types=1);

namespace Nova\Security;

/** Who performs an action: a signed-in Admin user (step 9 builds it from the session). */
final readonly class Actor
{
    public function __construct(
        public int $userId,
        public Role $role,
    ) {}

    public function isOwner(): bool
    {
        return $this->role === Role::Owner;
    }
}
