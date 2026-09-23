<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventProgramItemResource extends JsonResource
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
            'title' => $this->part_title,
            'designation' => $this->participant_description,
            'details' => $this->part_description,
            'sort_order' => $this->order,
            'attendee_id' => $this->attendee_id,
            'event_program_id' => $this->event_program_id,
            'order' => $this->order,
            'date' => $this->date?->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'part_title' => $this->part_title,
            'part_subtitle' => $this->part_subtitle,
            'part_description' => $this->part_description,
            'participant_name' => $this->participant_name,
            'participant_description' => $this->participant_description,
            'participant_photo_urls' => $this->participant_photo_urls,
            'part_remarks' => $this->part_remarks,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
