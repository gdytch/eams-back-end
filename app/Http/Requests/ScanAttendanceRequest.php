<?php

namespace App\Http\Requests;

use App\Models\AttendanceRecord;
use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class ScanAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = Event::find($this->input('event_id'));

        return $event !== null && $this->user()->can('create', [AttendanceRecord::class, $event]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'session_id' => ['required', 'integer', 'exists:event_sessions,id'],
            'qr_token' => ['required', 'string'],
            'override' => ['sometimes', 'boolean'],
        ];
    }
}
