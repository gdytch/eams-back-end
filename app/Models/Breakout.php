<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAttendanceStatus;
use Database\Factories\BreakoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable(['organization_id', 'event_id', 'title', 'description', 'date', 'start_time', 'end_time', 'room', 'capacity', 'topics', 'attendance_enabled'])]
class Breakout extends Model
{
    /** @use HasFactory<BreakoutFactory> */
    use BelongsToOrganization, HasAttendanceStatus, HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = ['attendance_enabled' => true];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'topics' => 'array', 'capacity' => 'integer', 'attendance_enabled' => 'boolean'];
    }

    /** @return BelongsTo<Event, $this> */
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

    public function tracksAttendance(): bool
    {
        return $this->attendance_enabled;
    }

    public function startsAt(): Carbon
    {
        return Carbon::parse($this->date->toDateString().' '.$this->start_time);
    }

    public function endsAt(): Carbon
    {
        return Carbon::parse($this->date->toDateString().' '.$this->end_time);
    }

    public function checkInOpensAt(): Carbon
    {
        return $this->startsAt()->subMinutes($this->event->check_in_window_minutes);
    }

    public function isOverbooked(int $picks): bool
    {
        return $this->capacity !== null && $picks > $this->capacity;
    }

    /**
     * Breakouts that check attendance in (attendance_enabled on).
     *
     * @param  Builder<Breakout>  $query
     */
    public function scopeTrackingAttendance(Builder $query): void
    {
        $query->where('breakouts.attendance_enabled', true);
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
