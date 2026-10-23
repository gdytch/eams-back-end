<?php

namespace App\Services;

use App\Exports\FormAnswersSheetExport;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The answers of one form as a table: a row per Attendee with a submission, a column per field.
 * Shared by the Excel export and the answers endpoint, so both always show the same thing.
 */
class FormAnswersTable
{
    /** @var Collection<int, FormField>|null */
    private ?Collection $columns = null;

    public function __construct(private Form $form) {}

    /** Active fields in form order, then archived fields that hold answers. */
    public function columns(): Collection
    {
        return $this->columns ??= $this->form->fields()->withExists('answers')->get()
            ->filter(fn (FormField $field) => $field->archived_at === null || $field->getAttribute('answers_exists'))
            ->sortBy(fn (FormField $field) => [$field->archived_at !== null ? 1 : 0, $field->sort_order, $field->id])
            ->values();
    }

    public function columnLabel(FormField $field): string
    {
        return $field->archived_at !== null ? "{$field->label} (removed)" : $field->label;
    }

    /**
     * Submissions of attendees that still exist, by last then first name.
     *
     * @return Builder<FormSubmission>
     */
    public function submissions(?string $search = null): Builder
    {
        $term = trim((string) $search);

        $query = FormSubmission::query()
            ->join('attendees', 'attendees.id', '=', 'form_submissions.attendee_id')
            ->where('form_submissions.form_id', $this->form->id);

        // Every word must match the first, middle or last name, or the email.
        foreach (preg_split('/\s+/', $term, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $like = '%'.preg_replace('/[!%_]/', '!$0', mb_strtolower($word)).'%';
            $query->where(function (Builder $query) use ($like) {
                foreach (['first_name', 'middle_name', 'last_name', 'email_address'] as $column) {
                    $query->orWhereRaw("lower(attendees.{$column}) like ? escape '!'", [$like]);
                }
            });
        }

        return $query
            ->orderByRaw('lower(attendees.last_name)')
            ->orderByRaw('lower(attendees.first_name)')
            ->orderBy('form_submissions.id')
            ->select('form_submissions.*')
            ->with(['attendee' => fn ($q) => $q->with(['union', 'mission', 'church']), 'answers.options']);
    }

    public function cell(?FormAnswer $answer): string
    {
        return match (true) {
            $answer === null => '',
            $answer->file_deleted_at !== null => FormAnswersSheetExport::DELETED_FILE_TEXT,
            $answer->file_path !== null => (string) $answer->file_name,
            $answer->options->isNotEmpty() => $answer->options->sortBy(['sort_order', 'id'])->pluck('label')->implode('; '),
            default => (string) $answer->value,
        };
    }

    /** Download link of an answer's file, `null` when it has no file or the file is deleted. */
    public function fileUrl(?FormAnswer $answer): ?string
    {
        if ($answer === null || $answer->file_path === null || $answer->file_deleted_at !== null) {
            return null;
        }

        return route('form-answers.file', $answer);
    }
}
