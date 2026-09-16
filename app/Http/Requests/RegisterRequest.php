<?php

namespace App\Http\Requests;

use App\Enums\OrganizationLevel;
use App\Models\Attendee;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email'),
                function ($attribute, $value, $fail) {
                    if (! $this->filled('invite_token')) {
                        // No invite token, so email must be unique in attendees table
                        $attendee = Attendee::withoutGlobalScopes()
                            ->where('email_address', $value)
                            ->first();

                        if ($attendee !== null) {
                            $fail('This email address is already in use.');
                        }
                    } else {
                        $inviteToken = $this->input('invite_token');

                        // Check if it's an event invite token or attendee invite token
                        $event = Event::withoutGlobalScopes()
                            ->where('invite_token', $inviteToken)
                            ->first();

                        if ($event !== null) {
                            // Event invite token: allow merge if email matches an unclaimed attendee in same org
                            $attendeeByEmail = Attendee::withoutGlobalScopes()
                                ->where('email_address', $value)
                                ->first();

                            if ($attendeeByEmail !== null) {
                                // Fail if already claimed by another user or belongs to different org
                                if ($attendeeByEmail->user_id !== null || ($attendeeByEmail->organization_id !== null && $attendeeByEmail->organization_id !== $event->organization_id)) {
                                    $fail('This email address is already in use.');
                                }
                                // Otherwise allow (will be merged in controller)
                            }
                        } else {
                            // Not an event token, check if it's a valid attendee invite token
                            $attendeeByToken = Attendee::withoutGlobalScopes()
                                ->where('invite_token', $inviteToken)
                                ->whereNull('user_id')
                                ->first();

                            if ($attendeeByToken === null) {
                                // Invalid token (neither event nor attendee)
                                $fail('Invalid invite token.');
                            } else {
                                // Attendee personal invite token: email must match or be unique in attendees
                                $attendeeByEmail = Attendee::withoutGlobalScopes()
                                    ->where('email_address', $value)
                                    ->first();

                                if ($attendeeByEmail !== null && $attendeeByEmail->id !== $attendeeByToken->id) {
                                    // Email exists on a different attendee
                                    $fail('This email address is already in use.');
                                }
                            }
                        }
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'invite_token' => ['nullable', 'string'],
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'union_id' => ['required', 'integer', Rule::exists('unions', 'id')->where('organization_id', $this->integer('organization_id'))],
            'mission_id' => ['nullable', 'integer', Rule::exists('missions', 'id')->where('organization_id', $this->integer('organization_id'))],
            'organization_level' => ['required', Rule::enum(OrganizationLevel::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email address is already in use.',
        ];
    }
}
