<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The registration view of an Attendee row (one person in one event): `id` and `attendee_id` are both the Attendee id.
 */
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
            'attendee_id' => $this->id,
            'qr_token' => $this->qr_token,
            'id_card_ready' => $this->id_card_generated_at !== null,
            'id_card_generated_at' => $this->id_card_generated_at,
            'registered_by' => $this->registered_by,
            'registered_at' => $this->registered_at,
            'event' => EventResource::make($this->whenLoaded('event')),
            'attendee' => AttendeeResource::make($this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
