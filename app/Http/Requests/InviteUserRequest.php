<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'organization_id' => [
                Rule::requiredIf(fn() => $this->user()->isSuperAdmin() && $this->input('role') !== UserRole::SuperAdmin->value),
                'nullable',
                'exists:organizations,id',
            ],
        ];
    }
}
