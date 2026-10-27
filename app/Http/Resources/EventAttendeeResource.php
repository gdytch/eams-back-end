<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * The registration view of an Attendee row (one person in one event): `id` and `attendee_id` are both the Attendee id.
 */
class EventAttendeeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $ownPicks = array_key_exists('own_picks', $this->resource->getAttributes());
        $attendee = AttendeeResource::make($this->resource);

        if ($ownPicks) {
            // Attendee-facing: the completeness check loads the admin `breakouts` relation with its `overbooked` pivot, so drop it.
            $attendee = Arr::except($attendee->resolve($request), 'breakouts');
        }

        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'attendee_id' => $this->id,
            'qr_token' => $this->qr_token,
            'id_card_ready' => $this->id_card_generated_at !== null,
            'id_card_generated_at' => $this->id_card_generated_at,
            'registered_by' => $this->registered_by,
            'registered_at' => $this->registered_at,
            'event' => $ownPicks && $this->event !== null
                // Set by the attendee's own registrations endpoint only; staff payloads never carry it.
                ? EventResource::make($this->event)->resolve($request) + ['has_breakouts' => $this->event->pick_mode !== null && (bool) $this->event->getAttribute('has_breakouts')]
                : EventResource::make($this->whenLoaded('event')),
            'attendee' => $attendee,
            // Set by the attendee's own registrations endpoint only; staff payloads never carry it.
            'picks' => $this->when($ownPicks, fn () => $this->resource->getAttributes()['own_picks']),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
