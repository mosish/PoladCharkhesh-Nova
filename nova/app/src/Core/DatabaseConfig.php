<?php

declare(strict_types=1);

namespace Nova\Core;

final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public string $password,
    ) {}

    public function withName(string $name): self
    {
        return new self($this->host, $this->port, $name, $this->user, $this->password);
    }
}
