<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventProgramItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $program = $this->route('event')?->program;
        if (! $program) {
            // Let the controller handle 404 for missing program
            return true;
        }

        return $this->user()->can('update', $program);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order' => ['nullable', 'integer', 'min:0'],
            'date' => ['nullable', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after_or_equal:start_time'],
            'part_title' => ['nullable', 'string', 'max:255'],
            'part_subtitle' => ['nullable', 'string', 'max:255'],
            'part_description' => ['nullable', 'string'],
            'participant_name' => ['nullable', 'string', 'max:255'],
            'speaker_id' => ['nullable', 'integer', Rule::exists('speakers', 'id')->where('event_id', $this->route('event')->id)],
            'participant_description' => ['nullable', 'string'],
            'part_remarks' => ['nullable', 'string'],
        ];
    }
}
