<?php

namespace App\Http\Requests;

use App\Models\Attendee;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendQrClaimEmailCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'claim_token' => ['required', 'string', 'size:64'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email'), Rule::unique(Attendee::class, 'email_address')],
        ];
    }
}
