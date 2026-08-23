<?php

namespace App\Http\Requests;

use App\Models\EventSession;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [EventSession::class, $this->route('event')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'session_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ];
    }
}
