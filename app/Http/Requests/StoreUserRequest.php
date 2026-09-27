<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
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
        $roles = $this->user()->isSuperAdmin()
            ? UserRole::cases()
            : array_filter(UserRole::cases(), fn (UserRole $role) => $role !== UserRole::SuperAdmin);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(array_map(fn (UserRole $role) => $role->value, $roles))],
            'organization_id' => [
                Rule::requiredIf(fn () => $this->user()->isSuperAdmin() && $this->input('role') !== UserRole::SuperAdmin->value),
                'nullable',
                Rule::exists('organizations', 'id')->when(! $this->user()->isSuperAdmin(), fn ($rule) => $rule->where('id', $this->user()->organization_id)),
            ],
        ];
    }
}
