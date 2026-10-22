<?php

namespace App\Exports;

use App\Models\Breakout;
use App\Models\Event;
use App\Services\BreakoutAttendanceReport;
use Illuminate\Support\Collection;

class BreakoutAttendanceSheetExport extends AttendanceSheetExport
{
    public function __construct(protected Event $event, protected Breakout $breakout) {}

    public function headings(): array
    {
        return ['First Name', 'Middle Name', 'Last Name', 'Organization Level Code', 'Status', 'Method', 'Check-in Time', 'Recorded By'];
    }

    public function collection(): Collection
    {
        return app(BreakoutAttendanceReport::class)->roster($this->event, $this->breakout)
            ->map(fn (array $row) => [
                'First Name' => $row['first_name'],
                'Middle Name' => $row['middle_name'],
                'Last Name' => $row['last_name'],
                'Organization Level Code' => $row['organization_level_code'],
                'Status' => $row['status'],
                'Method' => ucfirst($row['method'] ?? ''),
                'Check-in Time' => $row['check_in_at'] ?? '',
                'Recorded By' => $row['recorded_by'] ?? '',
            ]);
    }

    public function title(): string
    {
        // Prefix the id so truncated titles stay unique within the workbook; Excel allows 31 characters.
        $sanitized = preg_replace('/[\\\\\/:*?\[\]]/', '', $this->breakout->title);

        return substr("B{$this->breakout->id} ".substr($sanitized, 0, 18).' '.$this->breakout->date->format('m-d'), 0, 31);
    }
}
