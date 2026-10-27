<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'title' => $this->title,
            'template_url' => $this->template_path ? Storage::disk('public')->url($this->template_path) : null,
            'name_x' => $this->name_x,
            'name_y' => $this->name_y,
            'name_font_size' => $this->name_font_size,
            'font' => $this->font->value,
            'color' => $this->color,
            'page_size' => $this->page_size->value,
            'orientation' => $this->orientation->value,
            'status' => $this->status->value,
            'published_at' => $this->published_at,
            'audience' => [
                'union_ids' => $this->audience_union_ids ?? [],
                'mission_ids' => $this->audience_mission_ids ?? [],
                'attendee_ids' => $this->audience_attendee_ids ?? [],
            ],
            'release_rule' => $this->release_rule->value,
            'include_breakouts' => $this->include_breakouts,
            'release_at' => $this->release_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
