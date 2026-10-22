<?php

namespace App\Services;

use App\Enums\FormFieldType;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Form;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Rules\ValidImageUpload;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Saves an Attendee's answers to a published form. Files live on the private `local` disk.
 */
class FormAnswerService
{
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;

    private const FILE_MIMES = ['image/png', 'image/jpeg', 'application/pdf'];

    private const FILE_EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'application/pdf' => 'pdf'];

    /** The published form of the event, or a 404. */
    public function publishedForm(Event $event, int $formId): Form
    {
        return Form::withoutGlobalScopes()->where('event_id', $event->id)->published()->with(['fields' => fn ($q) => $q->active()->with(['options' => fn ($o) => $o->active()])])->findOrFail($formId);
    }

    /**
     * Published forms of the event, each with the Attendee's submission set as the `attendeeSubmission` relation.
     *
     * @return Collection<int, Form>
     */
    public function formsFor(Attendee $attendee, Event $event): Collection
    {
        $submissions = $attendee->formSubmissions()->with('answers.options')->get()->keyBy('form_id');

        return Form::withoutGlobalScopes()->where('event_id', $event->id)->published()->orderBy('sort_order')->orderBy('id')
            ->with(['fields' => fn ($q) => $q->active()->with(['options' => fn ($o) => $o->active()])])->get()
            ->each(fn (Form $form) => $form->setRelation('attendeeSubmission', $submissions->get($form->id)));
    }

    public function withSubmission(Attendee $attendee, Form $form): Form
    {
        $submission = $attendee->formSubmissions()->where('form_id', $form->id)->with('answers.options')->first();

        return $form->setRelation('attendeeSubmission', $submission);
    }

    /**
     * Validation rules for the `answers` payload, one entry per active field. A missing key leaves the answer as is.
     * Required fields are not enforced here: an Attendee may save a partial form, and completeness decides.
     *
     * @return array<string, mixed>
     */
    public function rules(Form $form): array
    {
        $rules = ['answers' => ['required', 'array']];

        foreach ($form->fields as $field) {
            $key = "answers.{$field->id}";
            $optionIds = $field->options->pluck('id')->all();

            match ($field->type) {
                FormFieldType::ShortText => $rules[$key] = ['nullable', 'string', 'max:255'],
                FormFieldType::LongText => $rules[$key] = ['nullable', 'string', 'max:5000'],
                FormFieldType::Number => $rules[$key] = ['nullable', 'numeric', 'between:-999999999,999999999'],
                FormFieldType::SingleChoice => $rules[$key] = ['nullable', Rule::in($optionIds)],
                FormFieldType::MultipleChoice => [$rules[$key] = ['nullable', 'array', 'max:'.max(1, count($optionIds))], $rules["$key.*"] = ['distinct', Rule::in($optionIds)]],
                FormFieldType::File => $rules[$key] = ['file', new ValidImageUpload(self::FILE_MIMES, self::MAX_FILE_BYTES)],
            };
        }

        return $rules;
    }

    /**
     * Apply the payload and return the answer snapshots before and after, for the admin audit.
     * The caller has validated against rules(); keys of unknown or archived fields were rejected there.
     *
     * @param  array<int|string, mixed>  $answers  field id => value
     * @return array{before: array<int, mixed>, after: array<int, mixed>}
     */
    public function save(Attendee $attendee, Form $form, array $answers): array
    {
        $newFiles = [];

        try {
            $result = DB::transaction(function () use ($attendee, $form, $answers, &$newFiles) {
                $oldFiles = [];
                // Serialises two saves of one Attendee, so the first-save submission is created once.
                Attendee::withoutGlobalScopes()->whereKey($attendee->id)->lockForUpdate()->first();

                $submission = FormSubmission::query()->where('form_id', $form->id)->where('attendee_id', $attendee->id)->first();
                $before = $this->snapshot($submission);

                foreach ($form->fields as $field) {
                    if (! array_key_exists($field->id, $answers) && ! array_key_exists((string) $field->id, $answers)) {
                        continue;
                    }
                    $value = $answers[$field->id] ?? $answers[(string) $field->id];
                    if ($value === null && $field->type === FormFieldType::File) {
                        continue;
                    }

                    // The submission exists once something is answered; an all-blank save does not start the form.
                    if ($submission === null) {
                        if ($this->isBlank($value)) {
                            continue;
                        }
                        $submission = FormSubmission::create(['form_id' => $form->id, 'attendee_id' => $attendee->id]);
                    }

                    $this->saveField($submission, $field, $value, $newFiles, $oldFiles);
                }

                // Rebuild the attendee row so the saving hook recomputes completeness from the new answers.
                $attendee->touch();

                // Replaced files go only once the outermost transaction commits, so a later rollback keeps them.
                DB::afterCommit(fn () => Storage::disk('local')->delete($oldFiles));

                return ['before' => $before, 'after' => $this->snapshot($submission?->unsetRelation('answers'))];
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($newFiles);

            throw $e;
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $newFiles
     * @param  array<int, string>  $oldFiles
     */
    private function saveField(FormSubmission $submission, FormField $field, mixed $value, array &$newFiles, array &$oldFiles): void
    {
        $answer = FormAnswer::query()->where('form_submission_id', $submission->id)->where('form_field_id', $field->id)->first();

        if ($field->type === FormFieldType::File) {
            $path = $this->storeFile($submission, $field, $value);
            $newFiles[] = $path;
            if ($answer?->file_path !== null) {
                $oldFiles[] = $answer->file_path;
            }
            ($answer ?? new FormAnswer(['form_submission_id' => $submission->id, 'form_field_id' => $field->id]))
                ->forceFill(['value' => null, 'file_path' => $path, 'file_name' => $this->cleanName($value), 'file_deleted_at' => null])->save();

            return;
        }

        if ($this->isBlank($value)) {
            $answer?->delete();

            return;
        }

        $answer ??= new FormAnswer(['form_submission_id' => $submission->id, 'form_field_id' => $field->id]);

        if ($field->type->hasOptions()) {
            $answer->forceFill(['value' => null])->save();
            $answer->options()->sync(array_map('intval', (array) $value));
        } else {
            $answer->forceFill(['value' => (string) $value])->save();
        }
    }

    private function storeFile(FormSubmission $submission, FormField $field, UploadedFile $file): string
    {
        // The stored type follows the file content (already checked by the rule), never the client's name.
        $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), (string) file_get_contents($file->getRealPath()));
        $extension = self::FILE_EXTENSIONS[$mime] ?? abort(422, 'Unsupported file type.');
        $path = "form-answers/{$submission->id}/{$field->id}-".bin2hex(random_bytes(8)).".{$extension}";

        Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
    }

    private function cleanName(UploadedFile $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '', $file->getClientOriginalName()) ?? '';

        return mb_substr($name === '' ? 'file' : $name, 0, 150);
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @return array<int, mixed> field id => value, option ids or file name
     */
    private function snapshot(?FormSubmission $submission): array
    {
        if ($submission === null) {
            return [];
        }

        return $submission->answers()->with('options')->get()->mapWithKeys(fn (FormAnswer $answer) => [
            $answer->form_field_id => $answer->options->isNotEmpty()
                ? $answer->options->pluck('id')->sort()->values()->all()
                : ($answer->file_path !== null ? ['file' => $answer->file_name] : $answer->value),
        ])->all();
    }
}
