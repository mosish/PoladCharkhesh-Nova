<?php

declare(strict_types=1);

namespace Nova\Tests\Unit\Backup;

use Nova\Backup\BackupConfig;
use Nova\Backup\Retention;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase
{
    /** @return list<array{id: string, at: int, trigger: string}> one scheduled set per day for $days days, ending at $end */
    private static function daily(int $days, int $end = 1_800_000_000, string $trigger = 'scheduler'): array
    {
        $sets = [];
        for ($i = 0; $i < $days; ++$i) {
            $at = $end - $i * 86_400;
            $sets[] = ['id' => 'B-' . gmdate('Ymd-His', $at) . '-0000', 'at' => $at, 'trigger' => $trigger];
        }

        return $sets;
    }

    /**
     * @param list<array{id: string, at: int, trigger: string}> $sets
     * @param list<string> $delete
     *
     * @return list<string>
     */
    private static function kept(array $sets, array $delete): array
    {
        return array_values(array_diff(array_map(static fn(array $s): string => $s['id'], $sets), $delete));
    }

    public function testKeepsSevenDailyFourWeeklyThreeMonthlyAndDeletesTheRest(): void
    {
        $sets = self::daily(200);
        $delete = Retention::prune($sets, new BackupConfig());
        $kept = self::kept($sets, $delete);
        // The 7 newest days, plus the newest set of each of the last 4 ISO weeks and 3 months (tiers overlap).
        self::assertGreaterThanOrEqual(7, \count($kept));
        self::assertLessThanOrEqual(7 + 4 + 3, \count($kept));
        self::assertContains($sets[0]['id'], $kept, 'the newest is always kept');
        foreach (\array_slice($sets, 0, 7) as $s) {
            self::assertContains($s['id'], $kept, 'the last seven days are all kept');
        }
        self::assertNotContains($sets[199]['id'], $kept);
        self::assertNotContains($sets[100]['id'], $kept);
        $months = array_unique(array_map(static fn(string $id): string => substr($id, 2, 6), $kept));
        self::assertLessThanOrEqual(4, \count($months), 'at most the current month plus three older ones');
    }

    public function testALongGapDoesNotKeepOldSetsAlive(): void
    {
        $end = 1_800_000_000;
        $old = ['id' => 'B-old', 'at' => $end - 200 * 86_400, 'trigger' => 'scheduler'];
        $new = ['id' => 'B-new', 'at' => $end, 'trigger' => 'scheduler'];
        self::assertSame(['B-old'], Retention::prune([$old, $new], new BackupConfig()), 'a set 200 days older than the newest is outside every window');
        $month = ['id' => 'B-month', 'at' => $end - 60 * 86_400, 'trigger' => 'scheduler'];
        self::assertSame([], Retention::prune([$month, $new], new BackupConfig()), 'but one from two months back is the monthly copy');
    }

    public function testNothingIsDeletedWhenThereIsNoMoreThanTheRetentionAllows(): void
    {
        $sets = self::daily(5);
        self::assertSame([], Retention::prune($sets, new BackupConfig()));
        self::assertSame([], Retention::prune([], new BackupConfig()));
    }

    public function testSafetyCopiesAreKeptSeparatelyAndNeverCrowdOutScheduledOnes(): void
    {
        $regular = self::daily(10);
        $safety = [];
        for ($i = 0; $i < 9; ++$i) {
            $at = 1_800_000_000 - $i * 3_600 - 10;
            $safety[] = ['id' => 'B-' . gmdate('Ymd-His', $at) . '-1111', 'at' => $at, 'trigger' => $i % 2 === 0 ? 'pre_migration' : 'pre_restore'];
        }
        $all = [...$regular, ...$safety];
        $delete = Retention::prune($all, new BackupConfig());
        $kept = self::kept($all, $delete);
        self::assertCount(5, array_intersect($kept, array_map(static fn(array $s): string => $s['id'], $safety)), 'the newest five safety copies');
        foreach (\array_slice($regular, 0, 7) as $s) {
            self::assertContains($s['id'], $kept);
        }
    }

    public function testTheNewestSuccessfulSetSurvivesEvenWithZeroRetention(): void
    {
        $sets = self::daily(4);
        $delete = Retention::prune($sets, new BackupConfig(daily: 0, weekly: 0, monthly: 0, keepSafety: 0));
        self::assertSame([$sets[1]['id'], $sets[2]['id'], $sets[3]['id']], $delete);
    }

    public function testSeveralSetsOnOneDayKeepOnlyTheNewest(): void
    {
        $end = 1_800_000_000;
        $sets = [
            ['id' => 'B-a', 'at' => $end, 'trigger' => 'manual'],
            ['id' => 'B-b', 'at' => $end - 600, 'trigger' => 'scheduler'],
            ['id' => 'B-c', 'at' => $end - 1200, 'trigger' => 'manual'],
        ];
        self::assertSame(['B-b', 'B-c'], Retention::prune($sets, new BackupConfig()));
    }
}
