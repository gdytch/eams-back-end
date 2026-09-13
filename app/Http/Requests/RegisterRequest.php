<?php

namespace App\Http\Requests;

use App\Models\Attendee;
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
                // Allow email if it matches the invite token's attendee, otherwise it must be unique in attendees table
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
                        // Invite token provided, check if it matches this email's attendee
                        $attendee = Attendee::withoutGlobalScopes()
                            ->where('invite_token', $this->input('invite_token'))
                            ->whereNull('user_id')
                            ->first();

                        // Only allow if email matches the invite token's attendee
                        if ($attendee === null || $attendee->email_address !== $value) {
                            // Either invalid token or email doesn't match the attendee
                            // But if there's a duplicate email elsewhere, reject it
                            $duplicate = Attendee::withoutGlobalScopes()
                                ->where('email_address', $value)
                                ->where('invite_token', '!=', $this->input('invite_token'))
                                ->first();

                            if ($duplicate !== null) {
                                $fail('This email address is already in use.');
                            }
                        }
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'invite_token' => ['nullable', 'string', 'exists:attendees,invite_token'],
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
