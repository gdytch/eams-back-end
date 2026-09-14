<?php

namespace App\Exports;

use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Support\Collection;

class EventSessionAttendanceSheetExport extends AttendanceSheetExport
{
    protected Event $event;

    protected EventSession $session;

    public function __construct(Event $event, EventSession $session)
    {
        $this->event = $event;
        $this->session = $session;
    }

    public function collection(): Collection
    {
        return $this->event->registrations()
            ->with('attendee.union', 'attendee.mission', 'attendee.church', 'attendanceRecords')
            ->get()
            ->map(function ($registration) {
                $attendanceRecord = $registration->attendanceRecords->firstWhere('event_session_id', $this->session->id);
                $checkInAt = $attendanceRecord?->check_in_at;
                $checkOutAt = $attendanceRecord?->check_out_at;
                $method = $attendanceRecord?->method?->value ?? 'no_show';
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
        // Sanitize session name: remove Excel-forbidden chars, truncate to fit within 31-char limit
        $sanitized = preg_replace('/[\\\\\/:*?\[\]]/', '', $this->session->name);
        $date = $this->session->session_date->format('m-d');
        $title = substr($sanitized, 0, 25) . ' ' . $date;

        return substr($title, 0, 31);
    }
}
