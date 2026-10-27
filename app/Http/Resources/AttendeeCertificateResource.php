<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Issued certificate as the Attendee sees it; expects the `certificate` relation. */
class AttendeeCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $certificate = $this->certificate;

        return [
            'id' => $certificate->id,
            'title' => $certificate->title,
            'event_id' => $certificate->event_id,
            'released_at' => $certificate->release_at ?? $certificate->published_at,
            'download_url' => route('attendee.events.certificates.download', [
                'event' => $certificate->event_id,
                'certificate' => $certificate->id,
            ]),
        ];
    }
}
