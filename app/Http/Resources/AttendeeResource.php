<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendeeResource extends JsonResource
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
            'organization_id' => $this->organization_id,
            'organization_level' => $this->organization_level,
            'union_id' => $this->union_id,
            'mission_id' => $this->mission_id,
            'church_id' => $this->church_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => trim("{$this->first_name} {$this->middle_name} {$this->last_name}"),
            'mobile_no' => $this->mobile_no,
            'email_address' => $this->email_address,
            'remarks' => $this->remarks,
            'photo_urls' => $this->photo_urls,
            'union' => UnionResource::make($this->whenLoaded('union')),
            'mission' => MissionResource::make($this->whenLoaded('mission')),
            'church' => ChurchResource::make($this->whenLoaded('church')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'registration_count' => $this->when(isset($this->duplicate_registration_count), $this->duplicate_registration_count),
            'attendance_count' => $this->when(isset($this->duplicate_attendance_count), $this->duplicate_attendance_count),
            'event_names' => $this->when(isset($this->duplicate_event_names), $this->duplicate_event_names),
            'has_linked_account' => $this->when(isset($this->duplicate_has_linked_account), $this->duplicate_has_linked_account),
        ];
    }
}
