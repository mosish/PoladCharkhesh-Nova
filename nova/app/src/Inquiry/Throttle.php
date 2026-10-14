<?php

declare(strict_types=1);

namespace Nova\Inquiry;

use Nova\Core\Sql;
use PDO;

/**
 * The per-address rate limit (05 §13). The address is kept only as an HMAC
 * under the inquiry key (a salted hash: it cannot be reversed or compared
 * across installations) and every row is deleted after 24 hours.
 *
 * Race-safe in the conservative direction: an attempt is inserted first and
 * the window is counted afterwards, including itself, so concurrent requests
 * can at worst both be refused, never both admitted past the limit. Requests
 * already over the limit are refused before inserting, so a flood cannot
 * grow the table.
 */
final readonly class Throttle
{
    public function __construct(
        private PDO $pdo,
        #[\SensitiveParameter]
        private string $key,
        private int $perHour,
        private int $perDay,
    ) {}

    public function addressHash(string $address): string
    {
        return hash_hmac('sha256', "inquiry-address|{$address}", $this->key);
    }

    /** The new attempt's id, or null when this address is over its limit. */
    public function admit(string $addressHash): ?int
    {
        $this->purge();
        if ($this->over($addressHash, false)) {
            return null;
        }
        Sql::run($this->pdo, 'INSERT INTO inquiry_attempt (address_hash) VALUES (?)', [$addressHash]);
        $id = (int) $this->pdo->lastInsertId();
        if ($this->over($addressHash, true)) {
            $this->finish($id, 'throttled');

            return null;
        }

        return $id;
    }

    /** @param 'stored'|'invalid'|'honeypot'|'token'|'throttled'|'failed' $outcome */
    public function finish(int $attempt, string $outcome): void
    {
        Sql::run($this->pdo, 'UPDATE inquiry_attempt SET outcome = ? WHERE id = ?', [$outcome, $attempt]);
    }

    /** Removes everything older than 24 hours (05 §13). */
    public function purge(): int
    {
        return Sql::run($this->pdo, 'DELETE FROM inquiry_attempt WHERE attempted_at < UTC_TIMESTAMP(6) - INTERVAL 24 HOUR LIMIT 1000')->rowCount();
    }

    private function over(string $addressHash, bool $includingThis): bool
    {
        $row = Sql::row($this->pdo, 'SELECT COALESCE(SUM(attempted_at >= UTC_TIMESTAMP(6) - INTERVAL 1 HOUR), 0) AS hour, COUNT(*) AS day FROM inquiry_attempt WHERE address_hash = ? AND attempted_at >= UTC_TIMESTAMP(6) - INTERVAL 24 HOUR', [$addressHash]);
        $hour = (int) ($row['hour'] ?? 0);
        $day = (int) ($row['day'] ?? 0);

        return $includingThis ? ($hour > $this->perHour || $day > $this->perDay) : ($hour >= $this->perHour || $day >= $this->perDay);
    }
}
