<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A Breakout as its attendee sees it. `picked`, `attendance_status` and `attendance_check_in_at` come from
 * attributes set by AttendeeDashboardData. Never carries capacity, pick counts or `overbooked`.
 */
class AttendeeBreakoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'date' => $this->date?->toDateString(),
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'room' => $this->room,
            'topics' => $this->topics,
            'speakers' => $this->speakers->map(fn ($speaker) => [
                'id' => $speaker->id,
                'name' => $speaker->name,
                'designation' => $speaker->designation,
            ])->values(),
            'program' => EventProgramResource::make($this->whenLoaded('program')),
            'picked' => (bool) $this->getAttribute('picked'),
            'status' => $this->getAttribute('attendance_status'),
            'check_in_at' => $this->getAttribute('attendance_check_in_at'),
        ];
    }
}
