<?php

declare(strict_types=1);

namespace Nova\Backup;

/**
 * Reads a `nova-dump/1` file statement by statement without loading it into
 * memory. It also proves the file is whole: the last line must be the footer
 * with the statement count, and the gzip stream must end cleanly.
 */
final class StatementReader
{
    /**
     * @return \Generator<int, string, mixed, int> the statements; the generator's return value is the footer's statement count
     *
     * @throws BackupError corrupt or incomplete
     */
    public static function read(string $gzPath): \Generator
    {
        $fh = @gzopen($gzPath, 'rb');
        if ($fh === false) {
            throw new BackupError('not_found', 'The dump file cannot be opened.');
        }
        try {
            $buffer = '';
            $seen = 0;
            $delimiter = DatabaseDump::SEPARATOR;
            while (true) {
                $chunk = @gzread($fh, 1_048_576);
                if ($chunk === false) {
                    throw new BackupError('corrupt', 'The dump is not a valid gzip stream.');
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, $delimiter)) !== false) {
                    yield substr($buffer, 0, $pos);
                    ++$seen;
                    $buffer = substr($buffer, $pos + \strlen($delimiter));
                }
                if ($chunk === '' && gzeof($fh)) {
                    break;
                }
            }
            if (preg_match('/^' . preg_quote(DatabaseDump::FOOTER, '/') . ' statements=([0-9]+)\n?$/', $buffer, $m) !== 1) {
                throw new BackupError('incomplete', 'The dump has no end marker: it was cut short.');
            }
            if ((int) $m[1] !== $seen) {
                throw new BackupError('corrupt', "The dump declares {$m[1]} statements but holds {$seen}.");
            }

            return $seen;
        } finally {
            gzclose($fh);
        }
    }
}
