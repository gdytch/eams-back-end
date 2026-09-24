<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QrClaimStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['qr_token' => ['required', 'string', 'max:512']];
    }
}
