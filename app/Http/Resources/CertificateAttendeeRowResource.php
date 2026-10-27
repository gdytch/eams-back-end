<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Attendee's row in a Certificate's run. Expects `attendee` loaded. */
class CertificateAttendeeRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'attendee_id' => $this->attendee_id,
            'full_name' => $this->attendee?->full_name,
            'email_address' => $this->attendee?->email_address,
            'status' => $this->status->value,
            'name_too_long' => $this->name_too_long,
            'has_file' => $this->hasFile(),
        ];
    }
}
