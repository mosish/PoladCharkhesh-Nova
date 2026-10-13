<?php

declare(strict_types=1);

namespace Nova\Security;

/** In-memory session for tests and command-line use: same contract, no cookies. */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string, mixed> */
    public array $data = [];

    /** Changes on regenerate() and destroy(), so tests can see a fresh id was issued. */
    public string $id;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function start(): void {}

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->id = bin2hex(random_bytes(16));
    }
}
