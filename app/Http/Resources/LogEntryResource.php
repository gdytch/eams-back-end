<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LogEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'timestamp' => $this->resource['timestamp'],
            'channel' => $this->resource['channel'],
            'level' => $this->resource['level'],
            'message' => $this->resource['message'],
            'raw' => $this->resource['raw'],
        ];
    }
}
