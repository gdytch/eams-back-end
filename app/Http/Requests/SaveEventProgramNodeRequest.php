<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEventProgramNodeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route('kind', 'program') !== 'program') {
            return;
        }

        $program = $this->route('event')->program;
        if (! $this->has('is_public')) {
            $this->merge(['is_public' => $program?->is_public ?? false]);
        }
        if (! $this->has('public_slug') && $program?->public_slug) {
            $this->merge(['public_slug' => $program->public_slug]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('event'));
    }

    public function rules(): array
    {
        $kind = $this->route('kind', 'program');
        $action = $this->route()->getActionMethod();
        if ($action === 'reorder') {
            return ['parent_id' => ['nullable', 'integer'], 'ids' => ['required', 'array', 'min:1'], 'ids.*' => ['required', 'integer', 'distinct']];
        }
        if (in_array($action, ['destroy', 'duplicate'], true)) {
            return [];
        }
        $rules = ['title' => [$kind === 'days' || $kind === 'program' ? 'nullable' : 'required', 'string', 'max:255']];
        if ($kind === 'program') {
            $program = $this->route('event')->program;
            $rules['is_public'] = ['required', 'boolean'];
            $rules['public_slug'] = [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('event_programs', 'public_slug')->ignore($program?->id),
            ];
        }
        if ($action === 'store' && $kind !== 'days') {
            $rules['parent_id'] = ['required', 'integer'];
        }
        if ($kind === 'days') {
            $rules['date'] = ['required', 'date_format:Y-m-d', Rule::unique('event_program_days')->where('event_program_id', $this->route('event')->program?->id)->ignore($this->route('node'))];
        }
        if (in_array($kind, ['sections', 'parts'], true)) {
            $rules['start_time'] = ['nullable', 'date_format:H:i'];
            $rules['end_time'] = ['nullable', 'date_format:H:i'];
        }
        if ($kind === 'parts') {
            $rules['participant_name'] = ['nullable', 'string', 'max:255'];
            $rules['speaker_id'] = ['nullable', 'integer', Rule::exists('speakers', 'id')->where('event_id', $this->route('event')->id)];
            $rules['attendee_id'] = ['nullable', 'integer', Rule::exists('attendees', 'id')->where('organization_id', $this->route('event')->organization_id)];
            $rules['designation'] = ['nullable', 'string', 'max:10000'];
            $rules['details'] = ['nullable', 'string', 'max:10000'];
        } else {
            $rules['description'] = ['nullable', 'string', 'max:10000'];
        }

        return $rules;
    }
}
