<?php

namespace App\Http\Requests;

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
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'invite_token' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    $isEventToken = Event::withoutGlobalScopes()->where('invite_token', $value)->exists();
                    $isAttendeeToken = Attendee::withoutGlobalScopes()
                        ->where('invite_token', $value)
                        ->where('invited_at', '>=', now()->subDays(config('auth.invite_expire')))
                        ->whereNull('user_id')
                        ->exists();

                    if (! $isEventToken && ! $isAttendeeToken) {
                        $fail('Invalid or expired invite.');
                    }
                },
            ],
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
