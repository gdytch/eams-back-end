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
use PhpOffice\PhpSpreadsheet\Style\Fill;

class AttendeeExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private Collection $attendees) {}

    public function collection(): Collection
    {
        return $this->attendees->map(function ($attendee) {
            return [
                'First Name' => $attendee->first_name,
                'Middle Name' => $attendee->middle_name ?? '',
                'Last Name' => $attendee->last_name,
                'Organization Level' => $attendee->organization_level?->value ?? '',
                'Union' => $attendee->union?->code ?? '',
                'Mission' => $attendee->mission?->code ?? '',
                'Church' => $attendee->church?->name ?? '',
                'Mobile No.' => $attendee->mobile_no ?? '',
                'Email Address' => $attendee->email_address ?? '',
                'Remarks' => $attendee->remarks ?? '',
            ];
        });
    }

    public function headings(): array
    {
        return [
            'First Name',
            'Middle Name',
            'Last Name',
            'Organization Level',
            'Union',
            'Mission',
            'Church',
            'Mobile No.',
            'Email Address',
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

                if ($data->count() > 0) {
                    $lastRow = $data->count() + 1;
                    $range = "A1:J{$lastRow}";
                    $sheet->getStyle($range)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle($range)->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle($range)->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle($range)->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN);
                }

                $sheet->freezePane('A2');
            },
        ];
    }

    public function title(): string
    {
        return 'Attendees';
    }
}
