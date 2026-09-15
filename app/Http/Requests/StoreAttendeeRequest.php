<?php

namespace App\Http\Requests;

use App\Enums\OrganizationLevel;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->can('create', Attendee::class)) {
            return false;
        }

        if ($this->boolean('auto_register') && ($eventId = $this->input('event_id'))) {
            $event = Event::find($eventId);

            return $event !== null && $this->user()->can('create', [EventRegistration::class, $event]);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->isSuperAdmin()
            ? $this->integer('organization_id')
            : $this->user()->organization_id;

        return [
            'organization_id' => [
                Rule::requiredIf(fn() => $this->user()->isSuperAdmin()),
                'nullable',
                'exists:organizations,id',
            ],
            'organization_level' => ['nullable', Rule::enum(OrganizationLevel::class)],
            'union_id' => ['nullable', Rule::exists('unions', 'id')->where('organization_id', $organizationId)],
            'mission_id' => ['nullable', Rule::exists('missions', 'id')->where('organization_id', $organizationId)],
            'church_id' => ['nullable', Rule::exists('churches', 'id')->where('organization_id', $organizationId)],
            'first_name' => ['required', 'string', 'max:100'],
            'auto_register' => ['sometimes', 'boolean'],
            'event_id' => [
                Rule::requiredIf(fn() => $this->boolean('auto_register')),
                'nullable',
                Rule::exists('events', 'id')->where('organization_id', $organizationId),
            ],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile_no' => ['nullable', 'string', 'max:20'],
            'email_address' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('attendees', 'email_address'),
                Rule::unique('users', 'email'),
            ],
            'remarks' => ['nullable', 'string'],
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
