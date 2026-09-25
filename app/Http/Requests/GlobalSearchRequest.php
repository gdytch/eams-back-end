<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GlobalSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:150'],
            'event_id' => ['nullable', 'integer', 'min:1'],
            'all_events' => ['sometimes', 'boolean'],
            'category' => ['nullable', Rule::in([
                'organizations', 'users', 'attendees', 'unions', 'missions', 'churches',
                'events', 'sessions', 'registrations', 'attendance', 'program', 'speakers',
            ])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $query = $this->input('q', '');
        $this->merge(['q' => is_string($query) ? trim($query) : $query]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->boolean('all_events') && $this->filled('event_id')) {
                $validator->errors()->add('event_id', 'Choose an event or all events, not both.');
            }
        }];
    }
}
