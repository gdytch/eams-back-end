<?php

namespace App\Http\Requests;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Event::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_id' => [
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin()),
                'nullable',
                'exists:organizations,id',
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(EventStatus::class)],
            'requires_check_out' => ['sometimes', 'boolean'],
            'check_in_window_minutes' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
