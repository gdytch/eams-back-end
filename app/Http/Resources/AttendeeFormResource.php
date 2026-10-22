<?php

namespace App\Http\Resources;

use App\Models\FormAnswer;
use App\Models\FormField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published form as an Attendee sees it: active fields and options, with the Attendee's own answers.
 * Expects the `attendeeSubmission` relation to be set (null when the form is not started).
 */
class AttendeeFormResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $submission = $this->resource->getRelation('attendeeSubmission');
        $answers = $submission?->answers->keyBy('form_field_id') ?? collect();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'is_required' => $this->is_required,
            'sort_order' => $this->sort_order,
            'started' => $submission !== null,
            'fields' => $this->fields->map(fn (FormField $field) => [
                'id' => $field->id,
                'type' => $field->type->value,
                'label' => $field->label,
                'help_text' => $field->help_text,
                'is_required' => $field->is_required,
                'options' => $field->options->map(fn ($option) => ['id' => $option->id, 'label' => $option->label])->values(),
                'answer' => $this->answer($answers->get($field->id)),
            ])->values(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function answer(?FormAnswer $answer): ?array
    {
        if ($answer === null || ! $answer->isAnswered()) {
            return null;
        }

        return [
            'value' => $answer->value,
            'option_ids' => $answer->options->pluck('id')->sort()->values(),
            'file' => $answer->file_path === null ? null : [
                'name' => $answer->file_name,
                'url' => route('form-answers.file', $answer),
            ],
            'file_deleted' => $answer->file_deleted_at !== null,
        ];
    }
}
