<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventProgramSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'description' => $this->description, 'start_time' => $this->start_time ? substr($this->start_time, 0, 5) : null, 'end_time' => $this->end_time ? substr($this->end_time, 0, 5) : null, 'sort_order' => $this->sort_order,
            'items' => EventProgramItemResource::collection($this->whenLoaded('items'))];
    }
}
