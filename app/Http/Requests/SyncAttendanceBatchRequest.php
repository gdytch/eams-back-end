<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncAttendanceBatchRequest extends FormRequest
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
            'scans' => ['required', 'array', 'min:1', 'max:100'],
            'scans.*.client_ref' => ['required', 'string'],
            'scans.*.event_id' => ['required', 'integer', 'exists:events,id'],
            'scans.*.session_id' => ['required', 'integer', 'exists:event_sessions,id'],
            'scans.*.qr_token' => ['nullable', 'string'],
            'scans.*.attendee_id' => ['required_without:scans.*.qr_token', 'nullable', 'integer', 'exists:attendees,id'],
            'scans.*.scanned_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'scans.*.method' => ['required', Rule::in(['qr', 'manual'])],
            'scans.*.override' => ['sometimes', 'boolean'],
        ];
    }
}
