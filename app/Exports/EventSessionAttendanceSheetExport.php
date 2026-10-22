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

    public function headings(): array
    {
        return [...parent::headings(), 'Via Breakout'];
    }

    public function collection(): Collection
    {
        $breakoutTitles = $this->event->breakouts()->pluck('title', 'id');

        return $this->event->attendees()
            ->inAttendanceOf($this->session)
            ->with('union', 'mission', 'attendanceRecords')
            ->get()
            ->map(function ($registration) use ($breakoutTitles) {
                $attendanceRecord = $registration->attendanceRecords->firstWhere('event_session_id', $this->session->id);
                $checkInAt = $attendanceRecord?->check_in_at;
                $checkOutAt = $attendanceRecord?->check_out_at;
                $method = $attendanceRecord?->method?->value ?? 'no_show';
                $status = match (true) {
                    $checkOutAt !== null => 'Checked Out',
                    $checkInAt !== null => 'Checked In',
                    default => $this->session->attendanceStatus(false, $registration->first_registered_at)?->label(),
                };

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
                    'Via Breakout' => $breakoutTitles[$attendanceRecord?->via_breakout_id] ?? '',
                ];
            });
    }

    public function title(): string
    {
        // Sanitize session name: remove Excel-forbidden chars, truncate to fit within 31-char limit
        $sanitized = preg_replace('/[\\\\\/:*?\[\]]/', '', $this->session->name);
        $date = $this->session->session_date->format('m-d');
        $title = substr($sanitized, 0, 25).' '.$date;

        return substr($title, 0, 31);
    }
}
