<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'name' => $this->name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'email_verified' => $this->hasVerifiedEmail(),
            'role' => $this->role,
            'organization_id' => $this->organization_id,
            'organization' => OrganizationResource::make($this->whenLoaded('organization')),
            'invited_at' => $this->invited_at,
            'invite_status' => $this->invite_token !== null ? 'pending' : ($this->invited_at !== null ? 'accepted' : null),
            'photo_urls' => $this->photo_urls,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
