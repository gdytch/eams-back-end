<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceRecordResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attendee_id' => $this->attendee_id,
            // Deprecated alias of attendee_id, removed in Release 2.
            'event_registration_id' => $this->attendee_id,
            'event_session_id' => $this->event_session_id,
            'breakout_id' => $this->breakout_id,
            'via_breakout_id' => $this->via_breakout_id,
            'is_walk_in' => $this->is_walk_in,
            'check_in_at' => $this->check_in_at,
            'check_out_at' => $this->check_out_at,
            'method' => $this->method,
            'recorded_by' => $this->recorded_by,
            'attendee' => EventAttendeeResource::make($this->whenLoaded('attendee')),
            // Deprecated alias of `attendee`, removed in Release 2.
            'event_registration' => EventAttendeeResource::make($this->whenLoaded('attendee')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
