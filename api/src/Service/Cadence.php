<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * Cadence stats — the engine behind the barber's "due" list and the
 * customer's "you're due" nudge.
 *
 * "Usual cadence" is the median gap between consecutive haircuts (median is
 * more robust than the mean against the occasional long gap). A person is
 * "due" when days-since-last-cut >= their usual cadence.
 */
final class Cadence
{
    /**
     * @param list<string> $dates    Haircut dates as 'Y-m-d' strings (any order).
     * @param int|null     $override Manual usual_cadence_days override (from persons).
     * @param string|null  $today    Reference date 'Y-m-d' (defaults to today); injectable for tests.
     * @return array<string,mixed>
     */
    public static function compute(array $dates, ?int $override = null, ?string $today = null): array
    {
        $ref = $today !== null
            ? new DateTimeImmutable($today)
            : new DateTimeImmutable('today');

        // Normalize + sort ascending.
        $parsed = [];
        foreach ($dates as $d) {
            $parsed[] = new DateTimeImmutable($d);
        }
        usort($parsed, static fn($a, $b) => $a <=> $b);

        $count = count($parsed);

        $firstCut = $count > 0 ? $parsed[0]->format('Y-m-d') : null;
        $lastCut  = $count > 0 ? $parsed[$count - 1]->format('Y-m-d') : null;

        // Gaps (in days) between consecutive cuts.
        $gaps = [];
        for ($i = 1; $i < $count; $i++) {
            $gaps[] = (int) $parsed[$i - 1]->diff($parsed[$i])->days;
        }

        $avgGap    = $gaps ? (int) round(array_sum($gaps) / count($gaps)) : null;
        $medianGap = self::median($gaps);

        // usual cadence: explicit override wins; otherwise the computed median
        // (needs at least one gap, i.e. >= 2 cuts).
        if ($override !== null) {
            $usual  = $override;
            $source = 'override';
        } elseif ($medianGap !== null) {
            $usual  = $medianGap;
            $source = 'computed';
        } else {
            $usual  = null;
            $source = null;
        }

        $daysSinceLast = $lastCut !== null
            ? (int) (new DateTimeImmutable($lastCut))->diff($ref)->days
            : null;

        $due        = false;
        $overdueBy  = null;
        if ($usual !== null && $daysSinceLast !== null) {
            $due       = $daysSinceLast >= $usual;
            $overdueBy = $daysSinceLast - $usual; // negative => days until due
        }

        return [
            'cut_count'          => $count,
            'first_cut'          => $firstCut,
            'last_cut'           => $lastCut,
            'days_since_last'    => $daysSinceLast,
            'avg_gap_days'       => $avgGap,
            'median_gap_days'    => $medianGap,
            'usual_cadence_days' => $usual,
            'cadence_source'     => $source,       // 'override' | 'computed' | null
            'due'                => $due,
            'overdue_by_days'    => $overdueBy,    // >=0 overdue; <0 days remaining; null unknown
        ];
    }

    /**
     * @param list<int> $values
     */
    private static function median(array $values): ?int
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values);
        $mid = intdiv($n, 2);

        if ($n % 2 === 1) {
            return $values[$mid];
        }

        return (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }
}
