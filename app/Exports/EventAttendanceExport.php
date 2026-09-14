<?php

namespace App\Exports;

use App\Models\Event;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class EventAttendanceExport implements Export, WithMultipleSheets
{
    use Exportable;

    protected Event $event;

    public function __construct(Event $event)
    {
        $this->event = $event;
    }

    public function sheets(): array
    {
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
