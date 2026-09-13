<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventSessionRosterEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event_registration_id' => $this->id,
            'attendee' => [
                'name' => $this->attendee->full_name,
                'id' => $this->attendee->id,
                'photo_urls' => $this->attendee->photo_urls,
            ],
            'union' => $this->attendee->union?->name,
            'mission' => $this->attendee->mission?->name,
            'attendance_status' => $this->session_check_in_at !== null ? 'Present' : 'Absent',
            'check_in_at' => $this->session_check_in_at,
            'check_out_at' => $this->session_check_out_at,
        ];
    }
}
