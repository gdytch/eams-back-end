<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class EventResource extends JsonResource
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
            'name' => $this->name,
            'description' => $this->description,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'venue' => $this->venue,
            'status' => $this->status,
            'requires_check_out' => $this->requires_check_out,
            'check_in_window_minutes' => $this->check_in_window_minutes,
            'id_card_background_url' => $this->id_card_background_path
                ? Storage::disk('public')->url($this->id_card_background_path)
                : null,
            'id_card_font_color' => $this->id_card_font_color ?? '#000000',
            'banner_urls' => $this->banner_paths
                ? collect($this->banner_paths)->mapWithKeys(fn ($path, $key) => [
                    $key => Storage::disk('public')->url($path),
                ])->toArray()
                : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'invite_token' => $this->invite_token,
            'program' => EventProgramResource::make($this->whenLoaded('program')),
        ];
    }
}
