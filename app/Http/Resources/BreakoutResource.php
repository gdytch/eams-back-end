<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BreakoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'title' => $this->title,
            'description' => $this->description,
            'date' => $this->date?->toDateString(),
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'room' => $this->room,
            'capacity' => $this->capacity,
            'topics' => $this->topics,
            'attendance_enabled' => (bool) $this->attendance_enabled,
            'speakers' => $this->whenLoaded('speakers', fn () => $this->speakers->map(fn ($speaker) => [
                'id' => $speaker->id,
                'name' => $speaker->name,
                'designation' => $speaker->designation,
            ])->values()),
            // Admin-only: attendee and public payloads never load the pick count.
            'picks_count' => $this->whenCounted('picks'),
            'overbooked' => $this->whenCounted('picks', fn ($picks) => $this->resource->isOverbooked($picks)),
            'program' => EventProgramResource::make($this->whenLoaded('program')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
