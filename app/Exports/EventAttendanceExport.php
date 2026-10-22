<?php

namespace App\Exports;

use App\Models\Breakout;
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

    protected ?Breakout $breakout;

    public function __construct(Event $event, ?EventSession $session = null, ?Breakout $breakout = null)
    {
        $this->event = $event;
        $this->session = $session;
        $this->breakout = $breakout;
    }

    public function sheets(): array
    {
        if ($this->session) {
            // Export only the specific session
            return [
                new EventSessionAttendanceSheetExport($this->event, $this->session),
            ];
        }

        if ($this->breakout) {
            return [
                new BreakoutOverviewSheetExport($this->event, $this->breakout),
                new BreakoutAttendanceSheetExport($this->event, $this->breakout),
            ];
        }

        $sheets = [
            new EventOverviewAttendanceSheetExport($this->event),
        ];

        // Add one sheet per session
        foreach ($this->event->sessions()->orderBy('session_date')->get() as $session) {
            $sheets[] = new EventSessionAttendanceSheetExport($this->event, $session);
        }

        $breakouts = $this->event->breakouts()->orderBy('date')->orderBy('start_time')->orderBy('id')->get();
        if ($breakouts->isNotEmpty()) {
            $sheets[] = new BreakoutOverviewSheetExport($this->event);
            foreach ($breakouts as $breakout) {
                $sheets[] = new BreakoutAttendanceSheetExport($this->event, $breakout);
            }
        }

        return $sheets;
    }
}
