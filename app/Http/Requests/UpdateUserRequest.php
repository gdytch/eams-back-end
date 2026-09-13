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

        return [
            'name' => $isStaff ? ['sometimes', 'string', 'max:255'] : ['prohibited'],
            'first_name' => $isStaff ? ['sometimes', 'string', 'max:100'] : ['prohibited'],
            'middle_name' => $isStaff ? ['nullable', 'string', 'max:100'] : ['prohibited'],
            'last_name' => $isStaff ? ['sometimes', 'string', 'max:100'] : ['prohibited'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($targetUser)],
            'password' => ['sometimes', 'string', 'min:8'],
            'role' => $isSelf ? ['prohibited'] : ['sometimes', Rule::enum(UserRole::class)],
        ];
    }
}
