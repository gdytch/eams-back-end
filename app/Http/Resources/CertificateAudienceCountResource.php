<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The number of Attendees in a Certificate's Audience; the wrapped resource is that integer. */
class CertificateAudienceCountResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        return ['count' => $this->resource];
    }
}
