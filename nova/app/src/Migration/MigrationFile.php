<?php

declare(strict_types=1);

namespace Nova\Migration;

/** One forward-only migration: `NNNN_snake_name.sql`. */
final readonly class MigrationFile
{
    public const string PATTERN = '/^(\d{4})_([a-z0-9]+(?:_[a-z0-9]+)*)\.sql$/';

    public function __construct(
        public int $version,
        public string $name,
        public string $path,
        public string $checksum,
    ) {}

    /** @return list<self> ordered by version; numbering must start at 1 with no gaps */
    public static function scan(string $directory): array
    {
        $files = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }
            if (preg_match(self::PATTERN, $entry, $m) !== 1) {
                throw new MigrationError("Unexpected file in the migrations folder: {$entry}");
            }
            $path = $directory . '/' . $entry;
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new MigrationError("Cannot read {$entry}");
            }
            $version = (int) $m[1];
            if (isset($files[$version])) {
                throw new MigrationError("Two migrations share version {$m[1]}");
            }
            $files[$version] = new self($version, $m[2], $path, hash('sha256', $bytes));
        }
        ksort($files);
        $expected = 1;
        foreach (array_keys($files) as $version) {
            if ($version !== $expected) {
                throw new MigrationError(\sprintf('Migration numbering has a gap: expected %04d, found %04d', $expected, $version));
            }
            ++$expected;
        }

        return array_values($files);
    }

    /**
     * Plain statements only (D12 rules out routines, so splitting on a
     * line-ending semicolon is safe). Full-line `--` comments are dropped.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        $sql = (string) file_get_contents($this->path);
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $parts = preg_split('/;\s*$/m', $sql) ?: [];

        return array_values(array_filter(array_map(trim(...), $parts), static fn(string $s): bool => $s !== ''));
    }
}
