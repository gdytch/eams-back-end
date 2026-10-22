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
            'session_id' => ['required_without:breakout_id', 'nullable', 'prohibits:breakout_id', 'integer', 'exists:event_sessions,id'],
            'breakout_id' => ['required_without:session_id', 'nullable', 'integer', 'exists:breakouts,id'],
            'attendee_id' => ['required', 'integer', 'exists:attendees,id'],
            'override' => ['sometimes', 'boolean'],
            'late' => ['sometimes', 'boolean'],
            'walk_in' => ['sometimes', 'boolean'],
        ];
    }
}
