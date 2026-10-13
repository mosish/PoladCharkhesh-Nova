<?php

declare(strict_types=1);

namespace Nova\Security;

/** The Admin session's storage: PHP's native file sessions on a server, an in-memory store in tests. */
interface SessionStore
{
    /** Opens the session the request's cookie names, or an empty one. */
    public function start(): void;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /** A new session id with the same data, the old id discarded (sign-in, privilege change). */
    public function regenerate(): void;

    /** Ends the session: data, id and cookie. */
    public function destroy(): void;
}
