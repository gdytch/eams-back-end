<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventSessionResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'session_date' => $this->session_date?->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'check_in_opens_at' => $this->checkInOpensAt(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
