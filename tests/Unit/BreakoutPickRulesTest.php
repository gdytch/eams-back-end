<?php

namespace Tests\Unit;

use App\Models\Breakout;
use App\Services\BreakoutPickRules;
use PHPUnit\Framework\TestCase;

class BreakoutPickRulesTest extends TestCase
{
    private function slot(string $date, string $start, string $end): Breakout
    {
        return (new Breakout)->forceFill(['date' => $date, 'start_time' => $start, 'end_time' => $end]);
    }

    public function test_touching_boundaries_do_not_overlap_but_other_overlaps_do(): void
    {
        $a = $this->slot('2026-06-25', '09:00', '10:00');

        $this->assertFalse(BreakoutPickRules::overlap($a, $this->slot('2026-06-25', '10:00', '11:00')));
        $this->assertTrue(BreakoutPickRules::overlap($a, $this->slot('2026-06-25', '09:59', '11:00')));
        $this->assertTrue(BreakoutPickRules::overlap($a, $this->slot('2026-06-25', '09:15', '09:30')));
        $this->assertFalse(BreakoutPickRules::overlap($a, $this->slot('2026-06-26', '09:00', '10:00')));
    }

    public function test_largest_non_overlapping_set(): void
    {
        $this->assertSame(0, BreakoutPickRules::largestNonOverlapping(collect()));

        $this->assertSame(3, BreakoutPickRules::largestNonOverlapping(collect([
            $this->slot('2026-06-25', '09:00', '12:00'), // blocks two shorter ones; greedy skips it
            $this->slot('2026-06-25', '09:00', '10:00'),
            $this->slot('2026-06-25', '10:00', '11:00'),
            $this->slot('2026-06-25', '11:00', '12:00'),
        ])));

        // Days are independent.
        $this->assertSame(2, BreakoutPickRules::largestNonOverlapping(collect([
            $this->slot('2026-06-25', '09:00', '10:00'),
            $this->slot('2026-06-26', '09:00', '10:00'),
            $this->slot('2026-06-26', '09:30', '10:30'),
        ])));
    }

    public function test_picks_break_on_count_or_overlap(): void
    {
        $a = $this->slot('2026-06-25', '09:00', '10:00');
        $b = $this->slot('2026-06-25', '10:00', '11:00');
        $c = $this->slot('2026-06-25', '10:30', '11:30');

        $this->assertFalse(BreakoutPickRules::picksBreak(collect([$a, $b]), 1, 2));
        $this->assertTrue(BreakoutPickRules::picksBreak(collect([$a]), 2, null));
        $this->assertTrue(BreakoutPickRules::picksBreak(collect([$a, $b]), 0, 1));
        $this->assertTrue(BreakoutPickRules::picksBreak(collect([$b, $c]), 0, null));
    }
}
