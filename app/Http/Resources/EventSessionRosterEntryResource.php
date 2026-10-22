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
            'attendee_id' => $this->id,
            // Deprecated alias of attendee_id, removed in Release 2.
            'event_registration_id' => $this->id,
            'attendee_name' => trim("{$this->first_name} {$this->last_name}"),
            'attendee_photo_url' => collect($this->photo_urls)->get('sm') ?? collect($this->photo_urls)->first(),
            'attendee' => [
                'name' => $this->full_name,
                'id' => $this->id,
                'photo_urls' => $this->photo_urls,
                'first_name' => $this->first_name,
                'middle_name' => $this->middle_name,
                'last_name' => $this->last_name,
            ],
            'union' => $this->union,
            'mission' => $this->mission,
            'organization_level' => $this->organization_level,
            'union_code' => $this->union?->code,
            'mission_code' => $this->mission?->code,
            'attendance_status' => $this->attendance_status?->label(),
            'check_in_at' => $this->session_check_in_at,
            'check_out_at' => $this->session_check_out_at,
        ];
    }
}
