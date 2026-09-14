<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;

abstract class AttendanceSheetExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithStyles, WithTitle
{
    protected const STATUS_CHECKED_IN_COLOR = 'C6EFCE';

    protected const STATUS_CHECKED_OUT_COLOR = 'BDECEC';

    protected const STATUS_NO_SHOW_COLOR = 'FFC7CE';

    abstract public function collection(): Collection;

    abstract public function title(): string;

    public function headings(): array
    {
        return [
            'First Name',
            'Middle Name',
            'Last Name',
            'Union',
            'Mission',
            'Church',
            'Mobile No.',
            'Email Address',
            'Status',
            'Method',
            'Check-in Time',
            'Check-out Time',
            'Remarks',
        ];
    }

    public function styles($sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '333333']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $data = $this->collection();

                // Apply borders to all cells and style header row
                if ($data->count() > 0) {
                    $lastRow = $data->count() + 1;
                    $range = "A1:M{$lastRow}";
                    $sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle($range)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle($range)->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle($range)->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
                }

                // Freeze pane at row 2
                $sheet->freezePane('A2');

                // Color-code Status column (I) based on value
                $dataStartRow = 2;
                $dataEndRow = $data->count() + 1;

                for ($row = $dataStartRow; $row <= $dataEndRow; $row++) {
                    $statusCell = $sheet->getCell("I{$row}");
                    $status = $statusCell->getValue();

                    $bgColor = match ($status) {
                        'Checked In' => self::STATUS_CHECKED_IN_COLOR,
                        'Checked Out' => self::STATUS_CHECKED_OUT_COLOR,
                        'Absent' => self::STATUS_NO_SHOW_COLOR,
                        default => null,
                    };

                    if ($bgColor) {
                        $statusCell->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bgColor);
                    }
                }
            },
        ];
    }
}
