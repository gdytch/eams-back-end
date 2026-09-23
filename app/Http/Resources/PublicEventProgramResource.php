<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicEventProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $event = $this->event;

        return [
            'name' => $event->name,
            'description' => $event->description,
            'start_date' => $event->start_date?->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
            'venue' => $event->venue,
            'banner_urls' => $event->banner_urls,
            'organization' => ['name' => $event->organization?->name],
            'program' => [
                'title' => $this->title,
                'description' => $this->description,
                'days' => $this->days->map(fn ($day) => [
                    'id' => $day->id,
                    'date' => $day->date?->toDateString(),
                    'title' => $day->title,
                    'sections' => $day->sections->map(fn ($section) => [
                        'id' => $section->id,
                        'title' => $section->title,
                        'description' => $section->description,
                        'start_time' => $section->start_time ? substr($section->start_time, 0, 5) : null,
                        'end_time' => $section->end_time ? substr($section->end_time, 0, 5) : null,
                        'items' => $section->items->map(fn ($item) => [
                            'id' => $item->id,
                            'title' => $item->part_title,
                            'designation' => $item->participant_description,
                            'details' => $item->part_description,
                            'participant_name' => $item->participant_name,
                            'participant_photo_urls' => $item->participant_photo_urls,
                            'start_time' => $item->start_time,
                            'end_time' => $item->end_time,
                        ])->values(),
                    ])->values(),
                ])->values(),
            ],
        ];
    }
}
