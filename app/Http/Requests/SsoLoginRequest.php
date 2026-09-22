<?php

namespace App\Http\Requests;

use App\Enums\OrganizationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SsoLoginRequest extends FormRequest
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
            'invite_token' => ['nullable', 'string'],
            'organization_level' => ['sometimes', 'nullable', Rule::enum(OrganizationLevel::class)],
            'organization_id' => ['sometimes', 'nullable', 'integer', 'exists:organizations,id'],
            'union_id' => ['sometimes', 'nullable', 'integer', Rule::exists('unions', 'id')->where('organization_id', $this->integer('organization_id'))],
            'mission_id' => ['sometimes', 'nullable', 'integer', Rule::exists('missions', 'id')->where('organization_id', $this->integer('organization_id'))],
        ];
    }
}
