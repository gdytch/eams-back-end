<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IssuedCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'attendee_id' => $this->attendee_id,
            'status' => $this->status->value,
            'name_too_long' => $this->name_too_long,
        ];
    }
}
