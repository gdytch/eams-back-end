<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Flat breakout entry for the attendee program. `picked` comes from the
 * `picked` attribute set by the controller. Never carries pick counts or `overbooked`.
 */
class AttendeeProgramBreakoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'date' => $this->date?->toDateString(),
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'room' => $this->room,
            'attendance_enabled' => $this->attendance_enabled,
            'speakers' => $this->speakers->map(fn ($speaker) => [
                'id' => $speaker->id,
                'name' => $speaker->name,
                'designation' => $speaker->designation,
            ])->values(),
            'picked' => (bool) $this->getAttribute('picked'),
        ];
    }
}
