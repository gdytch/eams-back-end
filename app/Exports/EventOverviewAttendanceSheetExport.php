<?php

namespace App\Exports;

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
        return $this->event->registrations()
            ->with('attendee', 'attendanceRecords')
            ->get()
            ->map(function ($registration) {
                $checkInRecord = $registration->attendanceRecords->first();
                $checkInAt = $checkInRecord?->check_in_at;
                $checkOutAt = $checkInRecord?->check_out_at;
                $method = $checkInRecord?->method?->value ?? 'no_show';
                $status = match (true) {
                    $checkOutAt !== null => 'Checked Out',
                    $checkInAt !== null => 'Checked In',
                    default => 'Absent',
                };

                return [
                    'First Name' => $registration->attendee->first_name,
                    'Middle Name' => $registration->attendee->middle_name ?? '',
                    'Last Name' => $registration->attendee->last_name,
                    'Organization Level Code' => $registration->attendee->organization_level_code ?? '',
                    'Status' => $status,
                    'Method' => ucfirst($method),
                    'Check-in Time' => $checkInAt?->format('Y-m-d H:i:s') ?? '',
                    'Check-out Time' => $checkOutAt?->format('Y-m-d H:i:s') ?? '',
                    'Remarks' => $registration->attendee->remarks ?? '',
                ];
            });
    }

    public function title(): string
    {
        return 'All Sessions';
    }
}
