<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('attendee'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->route('attendee')->organization_id;

        return [
            'union_id' => ['nullable', Rule::exists('unions', 'id')->where('organization_id', $organizationId)],
            'mission_id' => ['nullable', Rule::exists('missions', 'id')->where('organization_id', $organizationId)],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'profile_photo_path' => ['nullable', 'string'],
        ];
    }
}
