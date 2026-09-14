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
            ->with('attendee.union', 'attendee.mission', 'attendee.church', 'attendanceRecords')
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
                    'Union' => $registration->attendee->union?->code ?? '',
                    'Mission' => $registration->attendee->mission?->code ?? '',
                    'Church' => $registration->attendee->church?->name ?? '',
                    'Mobile No.' => $registration->attendee->mobile_no ?? '',
                    'Email Address' => $registration->attendee->email_address ?? '',
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
