<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveBreakoutPicksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorizes against the event or Attendee
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'breakout_ids' => ['present', 'array', 'max:100'],
            'breakout_ids.*' => ['integer', 'distinct'],
        ];
    }
}
