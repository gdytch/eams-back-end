<?php

namespace App\Exports;

use App\Enums\AttendanceStatus;
use App\Models\Event;
use Illuminate\Support\Collection;

class EventOverviewAttendanceSheetExport extends AttendanceSheetExport
{
    protected Event $event;

    public function __construct(Event $event)
    {
        $this->event = $event;
    }

    public function collection(): Collection
    {
        $sessions = $this->event->sessions;

        return $this->event->attendees()
            ->with(['union', 'mission', 'attendanceRecords' => fn ($records) => $records->counted()])
            ->get()
            ->map(function ($registration) use ($sessions) {
                $checkInRecord = $registration->attendanceRecords->whereNotNull('check_in_at')->first();
                $checkInAt = $checkInRecord?->check_in_at;
                $checkOutAt = $checkInRecord?->check_out_at;
                $method = $checkInRecord?->method?->value ?? 'no_show';
                // Across sessions: Absent if any session was missed, else Upcoming if any remains, else not expected.
                $statuses = $sessions->map(fn ($s) => $s->attendanceStatus(
                    $registration->attendanceRecords->where('event_session_id', $s->id)->whereNotNull('check_in_at')->isNotEmpty(),
                    $registration->first_registered_at,
                ));
                $status = match (true) {
                    $checkOutAt !== null => 'Checked Out',
                    $checkInAt !== null => 'Checked In',
                    $statuses->contains(AttendanceStatus::Absent) => AttendanceStatus::Absent->label(),
                    $statuses->contains(AttendanceStatus::Upcoming) => AttendanceStatus::Upcoming->label(),
                    default => null,
                };
                if ($status === null) {
                    return null;
                }

                return [
                    'First Name' => $registration->first_name,
                    'Middle Name' => $registration->middle_name ?? '',
                    'Last Name' => $registration->last_name,
                    'Organization Level Code' => $registration->organization_level_code ?? '',
                    'Status' => $status,
                    'Method' => ucfirst($method),
                    'Check-in Time' => $checkInAt?->format('Y-m-d H:i:s') ?? '',
                    'Check-out Time' => $checkOutAt?->format('Y-m-d H:i:s') ?? '',
                    'Remarks' => $registration->remarks ?? '',
                ];
            })
            ->filter()
            ->values();
    }

    public function title(): string
    {
        return 'All Sessions';
    }
}
