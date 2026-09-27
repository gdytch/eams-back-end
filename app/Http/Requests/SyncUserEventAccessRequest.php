<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncUserEventAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageEventAccess', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_ids' => ['present', 'array'],
            'event_ids.*' => [
                'integer',
                Rule::exists('events', 'id')->where('organization_id', $this->route('user')->organization_id),
            ],
        ];
    }
}
