<?php

namespace App\Exports;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Services\FormAnswersTable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Answers of one form: a row per Attendee with a submission, a column per field.
 * Every cell is written as text, so an answer starting with "=" is never a formula.
 */
class FormAnswersSheetExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithEvents, WithHeadings, WithStyles, WithTitle
{
    private const FIXED_COLUMNS = 4;

    public const DELETED_FILE_TEXT = 'File deleted (event archived)';

    /** @var array<int, array<int, string>> row number => [column index => download url] */
    private array $links = [];

    private ?FormAnswersTable $table;

    public function __construct(private ?Form $form, private string $title)
    {
        $this->table = $form === null ? null : new FormAnswersTable($form);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    private function columns(): Collection
    {
        return $this->table?->columns() ?? collect();
    }

    public function headings(): array
    {
        return [
            'Name', 'Email', 'Territory', 'Registration status',
            ...$this->columns()->map(fn (FormField $field) => $this->table->columnLabel($field))->all(),
        ];
    }

    public function array(): array
    {
        $this->links = [];
        if ($this->form === null) {
            return [];
        }

        $columns = $this->columns();
        $rows = [];

        $this->table->submissions()->get()
            ->each(function (FormSubmission $submission) use ($columns, &$rows) {
                $attendee = $submission->attendee;
                $answers = $submission->answers->keyBy('form_field_id');
                $row = [preg_replace('/\s+/', ' ', $attendee->full_name), $attendee->email_address ?? '', $attendee->territory, (string) $attendee->registrationStatus()];

                foreach ($columns as $index => $field) {
                    /** @var FormAnswer|null $answer */
                    $answer = $answers->get($field->id);
                    $row[] = $this->table->cell($answer);
                    if ($url = $this->table->fileUrl($answer)) {
                        $this->links[count($rows) + 2][self::FIXED_COLUMNS + $index + 1] = $url;
                    }
                }
                $rows[] = $row;
            });

        return $rows;
    }

    public function styles($sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '333333']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->freezePane('A2');

                foreach ($this->links as $row => $cells) {
                    foreach ($cells as $column => $url) {
                        $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row);
                        $cell->getHyperlink()->setUrl($url);
                        $cell->getStyle()->getFont()->getColor()->setRGB('0563C1');
                        $cell->getStyle()->getFont()->setUnderline('single');
                    }
                }
            },
        ];
    }
}
