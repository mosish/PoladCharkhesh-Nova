<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Core\Files;

/**
 * The crash journal of the publish or rollback in progress (05 §7): written
 * atomically and durably after every step, so an interrupted operation is
 * finished or rolled back on the next run or Admin request; no queue or
 * worker is needed.
 *
 * - kind 'publish': `identity` (what the release publishes, captured from the
 *   snapshot) and `summary` are stored before the switch, so recovery records
 *   the release that actually went live, not whatever the database holds now.
 * - kind 'rollback': written before the switch back; `release` is the target.
 *
 * @phpstan-type Identity array{products: list<int>, variants: list<int>}
 * @phpstan-type SnapshotPoint array{audit: int, gaps: list<int>}
 * @phpstan-type Entry array{kind?: string, release: string, record: int, strategy: string, previous: ?string, step: string, at: int, by?: array<string, int|string>, identity?: Identity, snapshot?: SnapshotPoint, summary?: array<string, mixed>}
 */
final readonly class PublishJournal
{
    public const array STEPS = ['started', 'prepared', 'built', 'verified', 'switched', 'recorded'];

    public function __construct(private string $file) {}

    /** @return Entry|null */
    public function read(): ?array
    {
        $json = @file_get_contents($this->file);
        if ($json === false) {
            return null;
        }
        $entry = json_decode($json, true);

        /** @var Entry|null */
        return \is_array($entry) ? $entry : null;
    }

    /** @param Entry $entry */
    public function write(array $entry): void
    {
        if (!is_dir(\dirname($this->file))) {
            mkdir(\dirname($this->file), 0775, true);
        }
        Files::writeAtomic($this->file, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $extra fields to add to the entry (identity, summary) */
    public function step(string $step, array $extra = []): void
    {
        $entry = $this->read();
        if ($entry !== null) {
            /** @var Entry $entry */
            $entry = [...$entry, ...$extra, 'step' => $step, 'at' => time()];
            $this->write($entry);
        }
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
            Files::syncDirectory(\dirname($this->file));
        }
    }
}
