<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\BreakoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'event_id', 'title', 'description', 'date', 'start_time', 'end_time', 'room', 'capacity', 'topics'])]
class Breakout extends Model
{
    /** @use HasFactory<BreakoutFactory> */
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'topics' => 'array', 'capacity' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class)->withPivot('sort_order')->orderByPivot('sort_order');
    }

    public function picks(): BelongsToMany
    {
        return $this->belongsToMany(Attendee::class, 'breakout_picks')->withTimestamps();
    }

    public function program(): HasOne
    {
        return $this->hasOne(EventProgram::class);
    }

    public function isOverbooked(int $picks): bool
    {
        return $this->capacity !== null && $picks > $this->capacity;
    }

    /**
     * @param  Builder<Breakout>  $query
     */
    public function scopeOverbooked(Builder $query): void
    {
        $query->whereNotNull('capacity')->whereRaw(
            '(select count(*) from breakout_picks where breakout_picks.breakout_id = breakouts.id) > breakouts.capacity'
        );
    }
}
