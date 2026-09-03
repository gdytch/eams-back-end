<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventAttendanceSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event_id' => $this->resource['event_id'],
            'event_name' => $this->resource['event_name'],
            'total_registered' => $this->resource['total_registered'],
            'total_checked_in' => $this->resource['total_checked_in'],
            'total_checked_out' => $this->resource['total_checked_out'],
            'total_no_show' => $this->resource['total_no_show'],
            'sessions' => $this->resource['sessions'] ?? [],
        ];
    }
}
