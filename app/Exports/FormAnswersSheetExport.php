<?php

namespace App\Exports;

use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSubmission;
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

    /** @var Collection<int, FormField>|null */
    private ?Collection $columns = null;

    /** @var array<int, array<int, string>> row number => [column index => download url] */
    private array $links = [];

    public function __construct(private ?Form $form, private string $title) {}

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

    /** Active fields in form order, then archived fields that hold answers. */
    private function columns(): Collection
    {
        if ($this->form === null) {
            return $this->columns = collect();
        }

        return $this->columns ??= $this->form->fields()->withExists('answers')->get()
            ->filter(fn (FormField $field) => $field->archived_at === null || $field->getAttribute('answers_exists'))
            ->sortBy(fn (FormField $field) => [$field->archived_at !== null ? 1 : 0, $field->sort_order, $field->id])
            ->values();
    }

    public function headings(): array
    {
        return [
            'Name', 'Email', 'Territory', 'Registration status',
            ...$this->columns()->map(fn (FormField $field) => $field->archived_at !== null ? "{$field->label} (removed)" : $field->label)->all(),
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

        FormSubmission::query()->where('form_id', $this->form->id)
            ->with(['attendee' => fn ($q) => $q->with(['union', 'mission', 'church']), 'answers.options'])
            ->get()
            ->filter(fn (FormSubmission $submission) => $submission->attendee !== null)
            ->sortBy(fn (FormSubmission $submission) => mb_strtolower($submission->attendee->last_name.' '.$submission->attendee->first_name))
            ->each(function (FormSubmission $submission) use ($columns, &$rows) {
                $attendee = $submission->attendee;
                $answers = $submission->answers->keyBy('form_field_id');
                $row = [preg_replace('/\s+/', ' ', $attendee->full_name), $attendee->email_address ?? '', $attendee->territory, (string) $attendee->registrationStatus()];

                foreach ($columns as $index => $field) {
                    /** @var FormAnswer|null $answer */
                    $answer = $answers->get($field->id);
                    $row[] = $this->cell($answer);
                    if ($answer !== null && $answer->file_path !== null && $answer->file_deleted_at === null) {
                        $this->links[count($rows) + 2][self::FIXED_COLUMNS + $index + 1] = route('form-answers.file', $answer);
                    }
                }
                $rows[] = $row;
            });

        return $rows;
    }

    private function cell(?FormAnswer $answer): string
    {
        return match (true) {
            $answer === null => '',
            $answer->file_deleted_at !== null => self::DELETED_FILE_TEXT,
            $answer->file_path !== null => (string) $answer->file_name,
            $answer->options->isNotEmpty() => $answer->options->sortBy(['sort_order', 'id'])->pluck('label')->implode('; '),
            default => (string) $answer->value,
        };
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
