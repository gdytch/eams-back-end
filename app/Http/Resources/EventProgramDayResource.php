<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventProgramDayResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'date' => $this->date->toDateString(), 'title' => $this->title, 'sort_order' => $this->sort_order,
            'sections' => EventProgramSectionResource::collection($this->whenLoaded('sections'))];
    }
}
