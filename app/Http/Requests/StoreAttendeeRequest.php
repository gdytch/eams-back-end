<?php

namespace App\Http\Requests;

use App\Enums\OrganizationLevel;
use App\Models\Attendee;
use App\Models\Event;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreAttendeeRequest extends FormRequest
{
    private ?Event $event = null;

    public function authorize(): Response|bool
    {
        if (! $this->user()->can('create', Attendee::class)) {
            return false;
        }

        // A missing event fails validation (422); an existing one needs permission to register people in it.
        $event = $this->event();

        return $event === null ? true : Gate::forUser($this->user())->inspect('create', [Attendee::class, $event]);
    }

    /**
     * The event the new Attendee registers in. The organization always follows the event, never client input.
     */
    public function event(): ?Event
    {
        return $this->event ??= Event::withoutGlobalScopes()->find($this->integer('event_id'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organizationId = $this->event()?->organization_id;
        $eventId = $this->integer('event_id');

        return [
            'event_id' => ['required', 'integer', Rule::exists('events', 'id')],
            'organization_level' => ['required', Rule::enum(OrganizationLevel::class)],
            'union_id' => ['required', Rule::exists('unions', 'id')->where('organization_id', $organizationId)],
            'mission_id' => ['nullable', Rule::exists('missions', 'id')->where('organization_id', $organizationId)],
            'church_id' => ['nullable', Rule::exists('churches', 'id')->where('organization_id', $organizationId)],
            'first_name' => ['required', 'string', 'max:100'],
            // Deprecated and ignored: the new Attendee is always the event registration.
            'auto_register' => ['sometimes', 'boolean'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile_no' => ['nullable', 'string', 'max:20'],
            'email_address' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('attendees', 'email_address')->where('event_id', $eventId),
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
