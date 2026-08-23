<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncUserEventAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_ids' => ['present', 'array'],
            'event_ids.*' => ['integer', 'exists:events,id'],
        ];
    }
}
