<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicEventResource extends JsonResource
{
    /**
     * Transform the resource into an array for public event landing page (minimal, safe fields).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'venue' => $this->venue,
            'status' => $this->status,
            'organization' => OrganizationResource::make($this->whenLoaded('organization')),
            'sessions' => EventSessionResource::collection($this->whenLoaded('sessions')),
            'program' => EventProgramResource::make($this->whenLoaded('program')),
            'banner_urls' => $this->banner_urls,
        ];
    }
}
