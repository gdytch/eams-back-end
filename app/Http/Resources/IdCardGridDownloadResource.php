<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IdCardGridDownloadResource extends JsonResource
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
            'event_id' => $this->event_id,
            'status' => $this->status->value,
            'progress_percentage' => $this->progress_percentage,
            'registration_ids' => $this->registration_ids,
            'failure_reason' => $this->failure_reason,
            'completed_at' => $this->completed_at,
            'download_url' => $this->status->value === 'completed'
              ? route('events.registrations.id-card-grid-download-file', [
                  'event' => $this->event_id,
                  'gridDownload' => $this->id,
              ])
              : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
