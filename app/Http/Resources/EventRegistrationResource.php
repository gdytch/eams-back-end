<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventRegistrationResource extends JsonResource
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
            'event_id' => $this->event_id,
            'attendee_id' => $this->attendee_id,
            'qr_token' => $this->qr_token,
            'id_card_ready' => $this->id_card_generated_at !== null,
            'id_card_generated_at' => $this->id_card_generated_at,
            'registered_by' => $this->registered_by,
            'registered_at' => $this->registered_at,
            'attendee' => AttendeeResource::make($this->whenLoaded('attendee')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
