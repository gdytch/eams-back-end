<?php

namespace App\Models;

use Database\Factories\EventProgramItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['event_program_section_id', 'attendee_id', 'speaker_id', 'event_program_id', 'order', 'date', 'start_time', 'end_time', 'part_title', 'part_subtitle', 'part_description', 'participant_name', 'participant_description', 'photo_paths', 'part_remarks'])]
class EventProgramItem extends Model
{
    /** @use HasFactory<EventProgramItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'photo_paths' => 'array',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(EventProgramSection::class, 'event_program_section_id');
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(EventProgram::class, 'event_program_id');
    }

    public function getParticipantPhotoUrlsAttribute(): ?array
    {
        return $this->photo_paths
            ? collect($this->photo_paths)->map(fn ($pathMap) => $pathMap['original'] ?? null)
                ->filter()
                ->map(fn ($path) => Storage::disk('public')->url($path))
                ->values()
                ->toArray()
            : null;
    }
}
