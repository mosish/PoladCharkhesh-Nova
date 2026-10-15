<?php

declare(strict_types=1);

namespace Nova\Backup;

/**
 * Which backup sets to keep (D13: 7 daily, 4 weekly, 3 monthly). The windows
 * are calendar windows counted back from the newest scheduled or manual set:
 * the newest set of each of the last N days, of each of the last N ISO weeks,
 * and of each of the last N months is kept (the tiers overlap, as in any
 * grandfather-father-son scheme). A long gap therefore does not keep old
 * sets alive. Safety copies made automatically before a migration or a
 * restore are kept separately (the newest few). The newest successful
 * scheduled or manual set is always kept, so pruning can never leave nothing.
 */
final class Retention
{
    /**
     * @param list<array{id: string, at: int, trigger: string}> $sets
     *
     * @return list<string> ids to delete
     */
    public static function prune(array $sets, BackupConfig $config): array
    {
        usort($sets, static fn(array $a, array $b): int => [$b['at'], $b['id']] <=> [$a['at'], $a['id']]);
        $safety = array_values(array_filter($sets, static fn(array $s): bool => str_starts_with($s['trigger'], 'pre_')));
        $regular = array_values(array_filter($sets, static fn(array $s): bool => !str_starts_with($s['trigger'], 'pre_')));

        $keep = [];
        foreach (\array_slice($safety, 0, $config->keepSafety) as $s) {
            $keep[$s['id']] = true;
        }
        if ($regular !== []) {
            $newest = $regular[0]['at'];
            $keep[$regular[0]['id']] = true;
            $windows = [
                [static fn(int $t): string => gmdate('Y-m-d', $t), self::keys($newest, $config->daily, static fn(int $t, int $i): int => $t - $i * 86_400, static fn(int $t): string => gmdate('Y-m-d', $t))],
                [static fn(int $t): string => gmdate('o-\WW', $t), self::keys($newest, $config->weekly, static fn(int $t, int $i): int => $t - $i * 604_800, static fn(int $t): string => gmdate('o-\WW', $t))],
                [static fn(int $t): string => gmdate('Y-m', $t), self::keys($newest, $config->monthly, static fn(int $t, int $i): int => (int) strtotime(gmdate('Y-m-01', $t) . " -{$i} month UTC"), static fn(int $t): string => gmdate('Y-m', $t))],
            ];
            foreach ($windows as [$bucket, $allowed]) {
                $seen = [];
                foreach ($regular as $s) {
                    $key = $bucket($s['at']);
                    if (isset($allowed[$key]) && !isset($seen[$key])) {
                        $seen[$key] = true;
                        $keep[$s['id']] = true;
                    }
                }
            }
        }

        $delete = [];
        foreach ($sets as $s) {
            if (!isset($keep[$s['id']])) {
                $delete[] = $s['id'];
            }
        }

        return $delete;
    }

    /**
     * The bucket keys of the last `$count` periods counted back from `$newest`.
     *
     * @param \Closure(int, int): int $step the time `$i` periods before `$newest`
     * @param \Closure(int): string $key the bucket key of a time
     *
     * @return array<string, true>
     */
    private static function keys(int $newest, int $count, \Closure $step, \Closure $key): array
    {
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $out[$key($step($newest, $i))] = true;
        }

        return $out;
    }
}
