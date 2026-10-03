<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class AttendeeSummaryExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles
{
    /** @param  Collection<int, array<string, mixed>>  $rows */
    public function __construct(private Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows->map(fn ($row) => [
            $row['attendee_name'],
            $row['organization_name'] ?? '',
            $row['present'],
            $row['absent'],
            $row['attendance_rating'].'%',
        ]);
    }

    public function headings(): array
    {
        return ['Attendee', 'Organization Level', 'Present', 'Absent', 'Attendance Rating'];
    }

    public function styles($sheet): array
    {
        $lastRow = $sheet->getHighestRow();
        $border = ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]];

        $sheet->freezePane('A2');
        $sheet->getStyle("A1:E{$lastRow}")->applyFromArray(['borders' => $border]);
        $sheet->getStyle("C2:E{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Zebra rows
        for ($row = 3; $row <= $lastRow; $row += 2) {
            $sheet->getStyle("A{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F5F5');
        }

        // Rating colour: green >= 80, amber >= 50, red below
        for ($row = 2; $row <= $lastRow; $row++) {
            $rate = (float) $sheet->getCell("E{$row}")->getValue();
            $color = $rate >= 80 ? 'C6EFCE' : ($rate >= 50 ? 'FFEB9C' : 'FFC7CE');
            $sheet->getStyle("E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
        }

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '333333']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ],
        ];
    }
}
