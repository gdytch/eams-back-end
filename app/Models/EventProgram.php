<?php

namespace App\Models;

use Database\Factories\EventProgramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['event_id', 'title', 'description', 'is_public', 'public_slug'])]
class EventProgram extends Model
{
    /** @use HasFactory<EventProgramFactory> */
    use HasFactory;

    protected $attributes = [
        'is_public' => false,
    ];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $program) {
            $program->public_slug ??= static::generateUniquePublicSlug(
                $program->title ?: $program->event?->name ?: 'event-program'
            );
        });
    }

    public static function generateUniquePublicSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'event-program';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('public_slug', $slug)
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(EventProgramDay::class)->orderBy('sort_order')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EventProgramItem::class);
    }
}
