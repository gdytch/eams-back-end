<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendeeResource extends JsonResource
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
            'organization_id' => $this->organization_id,
            'union_id' => $this->union_id,
            'mission_id' => $this->mission_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => trim("{$this->first_name} {$this->middle_name} {$this->last_name}"),
            'profile_photo_path' => $this->profile_photo_path,
            'union' => UnionResource::make($this->whenLoaded('union')),
            'mission' => MissionResource::make($this->whenLoaded('mission')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
