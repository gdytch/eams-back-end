<?php

namespace App\Models\Concerns;

use App\Enums\AttendanceStatus;
use App\Models\Event;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The one Present / Absent / Upcoming rule for a slot (sessions today).
 * Absent is never stored. The slot must implement endsAt().
 */
trait HasAttendanceStatus
{
    abstract public function endsAt(): Carbon;

    public function hasEnded(): bool
    {
        return $this->endsAt()->isPast();
    }

    /** Expected: fully registered for the first time before the slot ended. */
    public function isExpected(?CarbonInterface $firstRegisteredAt): bool
    {
        return $firstRegisteredAt !== null && $firstRegisteredAt->lt($this->endsAt());
    }

    /** Null means the Attendee is neither Present nor Expected here. */
    public function attendanceStatus(bool $checkedIn, ?CarbonInterface $firstRegisteredAt): ?AttendanceStatus
    {
        return match (true) {
            $checkedIn => AttendanceStatus::Present,
            ! $this->isExpected($firstRegisteredAt) => null,
            $this->hasEnded() => AttendanceStatus::Absent,
            default => AttendanceStatus::Upcoming,
        };
    }

    /** @return array{present: int, absent: int, upcoming: int, rate: float|int} */
    public function attendanceCounts(Event $event): array
    {
        $present = $event->attendees()->presentAt($this)->count();
        $notCheckedIn = $event->attendees()->notCheckedInAt($this)->count();
        $absent = $this->hasEnded() ? $notCheckedIn : 0;
        // Walk-ins and late registrants are Present but not Expected, so they stay out of the rate.
        $expectedPresent = $event->attendees()->expectedAt($this)->presentAt($this)->count();

        return [
            'present' => $present,
            'absent' => $absent,
            'upcoming' => $notCheckedIn - $absent,
            'rate' => $absent + $expectedPresent > 0 && $this->hasEnded() ? round($expectedPresent / ($expectedPresent + $absent) * 100, 1) : 0,
        ];
    }
}
