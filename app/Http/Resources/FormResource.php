<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormResource extends JsonResource
{
    /**
     * Admin payload: archived fields and options are included with their archived_at.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'title' => $this->title,
            'description' => $this->description,
            'is_required' => $this->is_required,
            'status' => $this->status->value,
            'sort_order' => $this->sort_order,
            'submissions_count' => $this->whenCounted('submissions'),
            'fields' => $this->whenLoaded('fields', fn () => $this->fields->map(fn ($field) => [
                'id' => $field->id,
                'type' => $field->type->value,
                'label' => $field->label,
                'help_text' => $field->help_text,
                'is_required' => $field->is_required,
                'sort_order' => $field->sort_order,
                'archived_at' => $field->archived_at,
                'options' => $field->options->map(fn ($option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                    'sort_order' => $option->sort_order,
                    'archived_at' => $option->archived_at,
                ])->values(),
            ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
