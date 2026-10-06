<?php

declare(strict_types=1);

namespace Nova\Cli;

/** Command output; tests pass in buffers instead of the process streams. */
final class Output
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $errors = [];

    public function __construct(private readonly bool $echo = true) {}

    public function line(string $text): void
    {
        $this->lines[] = $text;
        if ($this->echo) {
            fwrite(STDOUT, $text . PHP_EOL);
        }
    }

    public function error(string $text): void
    {
        $this->errors[] = $text;
        if ($this->echo) {
            fwrite(STDERR, $text . PHP_EOL);
        }
    }
}
