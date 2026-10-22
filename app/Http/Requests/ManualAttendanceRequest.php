<?php

namespace App\Http\Requests;

use App\Models\AttendanceRecord;
use App\Models\Event;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ManualAttendanceRequest extends FormRequest
{
    public function authorize(): Response|bool
    {
        $event = Event::find($this->input('event_id'));

        return $event === null ? false : Gate::forUser($this->user())->inspect('create', [AttendanceRecord::class, $event]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'session_id' => ['required', 'integer', 'exists:event_sessions,id'],
            'attendee_id' => ['required_without:event_registration_id', 'nullable', 'integer', 'exists:attendees,id'],
            // Deprecated: translated through attendees.legacy_registration_id. Use attendee_id.
            'event_registration_id' => ['required_without:attendee_id', 'nullable', 'integer'],
            'override' => ['sometimes', 'boolean'],
        ];
    }
}
