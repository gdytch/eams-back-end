<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class AcceptUserInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Validate that the token matches an existing pending invite with matching email
        $token = $this->input('token');
        $email = $this->input('email');

        $user = User::withoutGlobalScopes()
            ->where('invite_token', $token)
            ->where('email', $email)
            ->whereNotNull('invite_token')
            ->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired invite.'],
            ]);
        }
    }
}
