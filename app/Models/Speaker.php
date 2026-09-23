<?php

namespace App\Models;

use Database\Factories\SpeakerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['event_id', 'name', 'designation', 'organization', 'bio', 'photo_paths'])]
class Speaker extends Model
{
    /** @use HasFactory<SpeakerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['photo_paths' => 'array'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function programItems(): HasMany
    {
        return $this->hasMany(EventProgramItem::class);
    }

    public function getPhotoUrlsAttribute(): array
    {
        return collect($this->photo_paths ?? [])->mapWithKeys(fn ($path, $key) => [
            $key => Storage::disk('public')->url($path),
        ])->toArray();
    }

    public function getProgramScheduleAttribute(): array
    {
        if (! $this->relationLoaded('programItems')) {
            return [];
        }

        return $this->programItems
            ->sortBy(fn ($item) => ($item->date?->toDateString() ?? '').'|'.($item->start_time ?? ''))
            ->filter(fn ($item) => filled($item->part_subtitle) || filled($item->part_description))
            ->map(fn ($item) => [
                'id' => $item->id,
                'date' => $item->date?->toDateString(),
                'start_time' => $item->start_time,
                'end_time' => $item->end_time,
                'title' => $item->part_title ?: $item->participant_name ?: 'Scheduled program part',
                'topic' => $item->part_subtitle,
                'details' => $item->part_description,
            ])
            ->values()
            ->all();
    }
}
