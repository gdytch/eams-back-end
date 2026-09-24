<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyQrClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_token' => ['required', 'string', 'max:512'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'union_id' => ['required', 'integer', 'exists:unions,id'],
            'mission_id' => ['present', 'nullable', 'integer', Rule::exists('missions', 'id')->where('union_id', $this->integer('union_id'))],
        ];
    }
}
