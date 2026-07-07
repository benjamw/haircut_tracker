<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Service\Cadence;
use PHPUnit\Framework\TestCase;

/**
 * The cadence engine drives both the barber's "due" list and the customer's
 * "you're due" nudge, so its math is the highest-value thing to pin down.
 * Cadence is pure with an injectable "today", which makes this straightforward.
 */
final class CadenceTest extends TestCase
{
    public function testNoHaircutsHasNoCadence(): void
    {
        $s = Cadence::compute([], null, '2026-07-06');
        $this->assertSame(0, $s['cut_count']);
        $this->assertNull($s['usual_cadence_days']);
        $this->assertNull($s['days_since_last']);
        $this->assertFalse($s['due']);
        $this->assertNull($s['overdue_by_days']);
    }

    public function testSingleCutHasNoCadenceYet(): void
    {
        $s = Cadence::compute(['2026-06-01'], null, '2026-07-06');
        $this->assertSame(1, $s['cut_count']);
        $this->assertNull($s['usual_cadence_days']); // need >= 2 cuts
        $this->assertSame(35, $s['days_since_last']);
        $this->assertFalse($s['due']);
    }

    public function testRegularCadenceOverdue(): void
    {
        // 21-day gaps; last cut 27 days before "today" => overdue by 6.
        $s = Cadence::compute(
            ['2026-04-10', '2026-05-01', '2026-05-22', '2026-06-12'],
            null,
            '2026-07-09'
        );
        $this->assertSame(4, $s['cut_count']);
        $this->assertSame(21, $s['usual_cadence_days']);
        $this->assertSame('computed', $s['cadence_source']);
        $this->assertSame(27, $s['days_since_last']);
        $this->assertTrue($s['due']);
        $this->assertSame(6, $s['overdue_by_days']);
    }

    public function testNotYetDueReportsNegativeOverdue(): void
    {
        // 30-day cadence, last cut 24 days ago => due in 6 (overdue -6).
        $s = Cadence::compute(
            ['2026-04-18', '2026-05-18', '2026-06-15'],
            null,
            '2026-07-09'
        );
        $this->assertSame(29, $s['usual_cadence_days']); // median of [30,28]=29
        $this->assertFalse($s['due']);
        $this->assertSame(-5, $s['overdue_by_days']);   // 24 - 29
    }

    public function testOverrideBeatsComputed(): void
    {
        $s = Cadence::compute(['2026-05-01', '2026-05-22'], 45, '2026-07-06');
        $this->assertSame(45, $s['usual_cadence_days']);
        $this->assertSame('override', $s['cadence_source']);
    }

    public function testMedianResistsOutlier(): void
    {
        // Gaps [20, 20, 90]: median 20, not mean ~43.
        $s = Cadence::compute(
            ['2026-01-01', '2026-01-21', '2026-02-10', '2026-05-11'],
            null,
            '2026-07-06'
        );
        $this->assertSame(20, $s['median_gap_days']);
        $this->assertSame(20, $s['usual_cadence_days']);
    }

    public function testDueExactlyAtCadence(): void
    {
        // last cut exactly usual_cadence_days ago => due (>=).
        $s = Cadence::compute(['2026-06-01', '2026-06-15'], null, '2026-06-29');
        $this->assertSame(14, $s['usual_cadence_days']);
        $this->assertSame(14, $s['days_since_last']);
        $this->assertTrue($s['due']);
        $this->assertSame(0, $s['overdue_by_days']);
    }

    public function testUnsortedDatesAreHandled(): void
    {
        $s = Cadence::compute(['2026-06-12', '2026-04-10', '2026-05-22', '2026-05-01'], null, '2026-07-09');
        $this->assertSame('2026-04-10', $s['first_cut']);
        $this->assertSame('2026-06-12', $s['last_cut']);
        $this->assertSame(21, $s['usual_cadence_days']);
    }
}
