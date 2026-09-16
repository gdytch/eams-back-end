<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'organization_level' => ['sometimes', 'string', 'in:organization,union,mission,church'],
            'organization_id' => ['sometimes', 'integer'],
            'union_id' => ['sometimes', 'integer'],
            'mission_id' => ['sometimes', 'integer'],
        ];
    }
}
