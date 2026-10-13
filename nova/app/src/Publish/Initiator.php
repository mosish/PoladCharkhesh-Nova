<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Security\Actor;

/**
 * Who or what started a publish or rollback, for publish_record and the audit
 * trail: a signed-in Admin user, an operator at the command line (recorded
 * with the operating-system account, never a credential), or automatic crash
 * recovery finishing an interrupted operation.
 */
final readonly class Initiator
{
    private function __construct(
        public ?Actor $actor,
        public string $origin,
        public ?string $osUser = null,
    ) {}

    public static function admin(Actor $actor): self
    {
        return new self($actor, 'admin');
    }

    public static function cli(?string $osUser): self
    {
        return new self(null, 'cli', $osUser !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $osUser) === 1 ? $osUser : null);
    }

    /** The command-line process running now: its effective OS account name. */
    public static function currentProcess(): self
    {
        $name = null;
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            $name = \is_array($entry) ? $entry['name'] : null;
        }
        $env = getenv('USER');

        return self::cli($name ?? (\is_string($env) ? $env : null));
    }

    public static function recovery(): self
    {
        return new self(null, 'recovery');
    }

    /** @return array{origin: string, user_id?: int, os_user?: string} */
    public function toArray(): array
    {
        $out = ['origin' => $this->origin];
        if ($this->actor !== null) {
            $out['user_id'] = $this->actor->userId;
        }
        if ($this->osUser !== null) {
            $out['os_user'] = $this->osUser;
        }

        return $out;
    }
}
