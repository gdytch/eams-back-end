<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyQrClaimEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'claim_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'digits:6'],
        ];
    }
}
