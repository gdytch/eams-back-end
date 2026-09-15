<?php

namespace App\Exports;

use App\Models\Event;
use App\Models\EventSession;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class EventAttendanceExport implements Export, WithMultipleSheets
{
    use Exportable;

    protected Event $event;

    protected ?EventSession $session;

    public function __construct(Event $event, ?EventSession $session = null)
    {
        $this->event = $event;
        $this->session = $session;
    }

    public function sheets(): array
    {
        if ($this->session) {
            // Export only the specific session
            return [
                new EventSessionAttendanceSheetExport($this->event, $this->session),
            ];
        }

        $sheets = [
            new EventOverviewAttendanceSheetExport($this->event),
        ];

        // Add one sheet per session
        foreach ($this->event->sessions()->orderBy('session_date')->get() as $session) {
            $sheets[] = new EventSessionAttendanceSheetExport($this->event, $session);
        }

        return $sheets;
    }
}
