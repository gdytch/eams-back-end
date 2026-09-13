<?php

namespace App\Http\Requests;

use App\Models\EventRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [EventRegistration::class, $this->route('event')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->route('event')->organization_id;

        return [
            'attendee_id' => [
                'required_without_all:first_name,last_name',
                'nullable',
                'integer',
                Rule::exists('attendees', 'id')->where('organization_id', $organizationId),
            ],
            'first_name' => ['required_without:attendee_id', 'nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required_without:attendee_id', 'nullable', 'string', 'max:100'],
            'email_address' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('attendees', 'email_address'),
                Rule::unique('users', 'email'),
            ],
            'union_id' => ['nullable', Rule::exists('unions', 'id')->where('organization_id', $organizationId)],
            'mission_id' => ['nullable', Rule::exists('missions', 'id')->where('organization_id', $organizationId)],
            'override_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email_address.unique' => 'This email address is already in use by another attendee or account.',
        ];
    }
}
