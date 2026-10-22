<?php

namespace App\Services;

use App\Models\Breakout;
use App\Models\Event;
use Illuminate\Support\Collection;

/**
 * Pick rules shared by the event pick setting, breakout edits and the completeness step.
 */
class BreakoutPickRules
{
    /** Same date and startA < endB && startB < endA; touching boundaries do not overlap. */
    public static function overlap(Breakout $a, Breakout $b): bool
    {
        return $a->date->toDateString() === $b->date->toDateString()
            && substr($a->start_time, 0, 5) < substr($b->end_time, 0, 5)
            && substr($b->start_time, 0, 5) < substr($a->end_time, 0, 5);
    }

    /**
     * Size of the largest set of mutually non-overlapping breakouts (greedy by earliest end, per date).
     *
     * @param  Collection<int, Breakout>  $breakouts
     */
    public static function largestNonOverlapping(Collection $breakouts): int
    {
        return $breakouts->groupBy(fn (Breakout $b) => $b->date->toDateString())->sum(function (Collection $day) {
            $count = 0;
            $lastEnd = '';
            foreach ($day->sortBy(fn (Breakout $b) => substr($b->end_time, 0, 5)) as $breakout) {
                if (substr($breakout->start_time, 0, 5) >= $lastEnd) {
                    $count++;
                    $lastEnd = substr($breakout->end_time, 0, 5);
                }
            }

            return $count;
        });
    }

    /** @param  Collection<int, Breakout>  $picks */
    public static function picksBreak(Collection $picks, int $min, ?int $max): bool
    {
        if ($picks->count() < $min || ($max !== null && $picks->count() > $max)) {
            return true;
        }

        return $picks->values()->contains(
            fn (Breakout $a, int $i) => $picks->slice($i + 1)->contains(fn (Breakout $b) => self::overlap($a, $b))
        );
    }

    /** True when any Attendee holding picks would break the range. */
    public static function anyAttendeePicksBreak(Event $event, int $min, ?int $max): bool
    {
        return $event->attendees()->whereHas('breakouts')->with('breakouts')->cursor()
            ->contains(fn ($attendee) => self::picksBreak($attendee->breakouts, $min, $max));
    }
}
