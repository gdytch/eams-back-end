<?php

namespace App\Exports;

use App\Models\Breakout;
use App\Models\Event;
use App\Services\BreakoutAttendanceReport;
use Illuminate\Support\Collection;

class BreakoutOverviewSheetExport extends AttendanceSheetExport
{
    public function __construct(protected Event $event, protected ?Breakout $breakout = null) {}

    public function headings(): array
    {
        return ['Breakout', 'Date', 'Start', 'End', 'Room', 'Capacity', 'Picks', 'Overbooked', 'Expected', 'Present', 'Absent', 'Walk-ins', 'Rate'];
    }

    public function collection(): Collection
    {
        return app(BreakoutAttendanceReport::class)->overview($this->event)
            ->when($this->breakout, fn (Collection $rows) => $rows->where('breakout_id', $this->breakout->id))
            ->map(fn (array $row) => [
                'Breakout' => $row['title'],
                'Date' => $row['date'],
                'Start' => $row['start_time'],
                'End' => $row['end_time'],
                'Room' => $row['room'] ?? '',
                'Capacity' => $row['capacity'] ?? '',
                'Picks' => $row['picks'],
                'Overbooked' => $row['overbooked'] ? 'Yes' : 'No',
                'Expected' => $row['expected'],
                'Present' => $row['present'],
                'Absent' => $row['absent'],
                'Walk-ins' => $row['walk_ins'],
                'Rate' => $row['rate'].'%',
            ])
            ->values();
    }

    public function title(): string
    {
        return 'Breakouts';
    }
}
