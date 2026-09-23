<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpeakerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'designation' => $this->designation,
            'organization' => $this->organization,
            'bio' => $this->bio,
            'photo_urls' => $this->photo_urls,
            'program_schedule' => $this->program_schedule,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
