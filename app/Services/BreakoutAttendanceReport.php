<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
use Illuminate\Support\Collection;

/**
 * Breakout attendance numbers and rosters, on the shared Present / Absent / Upcoming rule.
 */
class BreakoutAttendanceReport
{
    public function overview(Event $event): Collection
    {
        return $event->breakouts()->where('attendance_enabled', true)->orderBy('date')->orderBy('start_time')->orderBy('id')->withCount('picks')->get()
            ->map(function (Breakout $breakout) use ($event) {
                $counts = $breakout->attendanceCounts($event);

                return [
                    'breakout_id' => $breakout->id,
                    'title' => $breakout->title,
                    'date' => $breakout->date->toDateString(),
                    'start_time' => $breakout->start_time,
                    'end_time' => $breakout->end_time,
                    'room' => $breakout->room,
                    'capacity' => $breakout->capacity,
                    'picks' => $breakout->picks_count,
                    'overbooked' => $breakout->isOverbooked($breakout->picks_count),
                    'expected' => $event->attendees()->expectedAt($breakout)->count(),
                    'present' => $counts['present'],
                    'absent' => $counts['absent'],
                    'upcoming' => $counts['upcoming'],
                    'walk_ins' => AttendanceRecord::where('breakout_id', $breakout->id)->where('is_walk_in', true)->whereNotNull('check_in_at')->count(),
                    'rate' => $counts['rate'],
                ];
            });
    }

    /**
     * Expected pick holders plus walk-ins, one row each.
     */
    public function roster(Event $event, Breakout $breakout): Collection
    {
        if (! $breakout->attendance_enabled) {
            return $this->picksOnlyRoster($event, $breakout);
        }

        return $event->attendees()
            ->inAttendanceOf($breakout)
            ->with(['union', 'mission', 'attendanceRecords' => fn ($q) => $q->where('breakout_id', $breakout->id)->with('recordedBy')])
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->map(function (Attendee $attendee) use ($breakout) {
                $record = $attendee->attendanceRecords->first(fn ($r) => $r->check_in_at !== null);
                $status = match (true) {
                    $record?->is_walk_in => 'Walk-in',
                    default => $breakout->attendanceStatus($record !== null, $attendee->first_registered_at)?->label(),
                };

                return [
                    'attendee_id' => $attendee->id,
                    'attendee_name' => $attendee->full_name,
                    'first_name' => $attendee->first_name,
                    'middle_name' => $attendee->middle_name ?? '',
                    'last_name' => $attendee->last_name,
                    'union' => $attendee->union?->name,
                    'mission' => $attendee->mission?->name,
                    'organization_level_code' => $attendee->organization_level_code ?? '',
                    'status' => $status,
                    'check_in_at' => $record?->check_in_at?->format('Y-m-d H:i:s'),
                    'method' => $record?->method?->value,
                    'recorded_by' => $record ? ($record->recordedBy->name ?? 'System') : null,
                ];
            });
    }

    /**
     * A breakout with attendance off has no Expected Attendees and shows no attendance: pick holders only, no status or check-in.
     */
    private function picksOnlyRoster(Event $event, Breakout $breakout): Collection
    {
        return $event->attendees()
            ->whereHas('breakouts', fn ($picks) => $picks->whereKey($breakout->id))
            ->with(['union', 'mission'])
            ->orderBy('last_name')->orderBy('first_name')
            ->get()
            ->map(fn (Attendee $attendee) => [
                'attendee_id' => $attendee->id,
                'attendee_name' => $attendee->full_name,
                'first_name' => $attendee->first_name,
                'middle_name' => $attendee->middle_name ?? '',
                'last_name' => $attendee->last_name,
                'union' => $attendee->union?->name,
                'mission' => $attendee->mission?->name,
                'organization_level_code' => $attendee->organization_level_code ?? '',
                'status' => null,
                'check_in_at' => null,
                'method' => null,
                'recorded_by' => null,
            ]);
    }
}
