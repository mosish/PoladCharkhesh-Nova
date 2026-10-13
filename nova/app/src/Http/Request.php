<?php

declare(strict_types=1);

namespace Nova\Http;

/** The parts of an HTTP request the Admin uses. Built from the PHP globals on a server, directly in tests. */
final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $host,
        public array $query = [],
        public array $post = [],
        public string $clientAddress = '',
        public ?string $origin = null,
        public ?string $fetchSite = null,
    ) {}

    public static function fromGlobals(): self
    {
        $uri = \is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        /** @var array<string, mixed> $query */
        $query = $_GET;
        /** @var array<string, mixed> $post */
        $post = $_POST;

        return new self(
            strtoupper(\is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET'),
            \is_string($path) ? rawurldecode($path) : '/',
            strtolower(\is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : ''),
            $query,
            $post,
            // Only the TCP peer: forwarded-for headers are client-controlled.
            \is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
            \is_string($_SERVER['HTTP_ORIGIN'] ?? null) ? $_SERVER['HTTP_ORIGIN'] : null,
            \is_string($_SERVER['HTTP_SEC_FETCH_SITE'] ?? null) ? $_SERVER['HTTP_SEC_FETCH_SITE'] : null,
        );
    }

    /** The host without a port. */
    public function hostname(): string
    {
        return (string) preg_replace('/:\d+$/', '', $this->host);
    }

    public function input(string $key): string
    {
        $value = $this->post[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    public function queryString(string $key): string
    {
        $value = $this->query[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /** @return array<array-key, mixed> */
    public function inputArray(string $key): array
    {
        $value = $this->post[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }
}
