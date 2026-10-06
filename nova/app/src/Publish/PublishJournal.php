<?php

declare(strict_types=1);

namespace Nova\Publish;

use Nova\Core\Files;

/**
 * The crash journal of the publish in progress (05 §7): written atomically
 * after every step, so an interrupted publish is finished or rolled back on
 * the next run or Admin request; no queue or worker is needed.
 *
 * @phpstan-type Entry array{release: string, record: int, strategy: string, previous: ?string, step: string, at: int}
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
        Files::writeAtomic($this->file, json_encode($entry, JSON_THROW_ON_ERROR));
    }

    public function step(string $step): void
    {
        $entry = $this->read();
        if ($entry !== null) {
            $entry['step'] = $step;
            $entry['at'] = time();
            $this->write($entry);
        }
    }

    public function clear(): void
    {
        @unlink($this->file);
    }
}
