<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
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
            'scans.*.event_registration_id' => ['nullable', 'integer', 'exists:event_registrations,id'],
            'scans.*.scanned_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'scans.*.method' => ['required', Rule::in(['qr', 'manual'])],
            'scans.*.override' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Ensure at least one of qr_token or event_registration_id is present for each scan.
        $this->merge([
            'scans' => collect($this->input('scans', []))->map(function ($scan) {
                if (empty($scan['qr_token']) && empty($scan['event_registration_id'])) {
                    // This will fail validation later with a custom message.
                }

                return $scan;
            })->all(),
        ]);
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = $validator->errors();

        // Add custom validation for each scan.
        foreach ($this->input('scans', []) as $index => $scan) {
            if (empty($scan['qr_token']) && empty($scan['event_registration_id'])) {
                $errors->add("scans.{$index}.method", 'Either qr_token or event_registration_id must be provided.');
            }
        }

        parent::failedValidation($validator);
    }
}
