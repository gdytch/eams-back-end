<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $targetUser = $this->route('user');
        $isStaff = ! $this->user()->isAttendee();
        $isSelf = $this->user()->id === $targetUser->id;
        $emailChanges = $this->filled('email') && $this->input('email') !== $targetUser->email;
        $roles = $this->user()->isSuperAdmin()
            ? UserRole::cases()
            : array_filter(UserRole::cases(), fn (UserRole $role) => $role !== UserRole::SuperAdmin);

        return [
            'name' => $isStaff ? ['sometimes', 'string', 'max:255'] : ['prohibited'],
            'first_name' => $isStaff ? ['sometimes', 'string', 'max:100'] : ['prohibited'],
            'middle_name' => $isStaff ? ['nullable', 'string', 'max:100'] : ['prohibited'],
            'last_name' => $isStaff ? ['sometimes', 'string', 'max:100'] : ['prohibited'],
            'email' => $isSelf && $emailChanges ? ['prohibited'] : ['sometimes', 'email', Rule::unique('users', 'email')->ignore($targetUser)],
            'password' => ['sometimes', 'string', 'min:8'],
            'current_password' => $isSelf && $this->filled('password')
                ? ['required', 'current_password:sanctum']
                : ['prohibited'],
            'role' => $isSelf ? ['prohibited'] : ['sometimes', Rule::in(array_map(fn (UserRole $role) => $role->value, $roles))],
        ];
    }
}
