<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['event_id', 'name', 'description', 'session_date', 'start_time', 'end_time'])]
class EventSession extends Model
{
    /** @use HasFactory<\Database\Factories\EventSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'session_date' => 'date',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function startsAt(): Carbon
    {
        return Carbon::parse($this->session_date->toDateString().' '.$this->start_time);
    }

    /**
     * The moment check-in opens: session start minus the event's check-in window.
     */
    public function checkInOpensAt(): Carbon
    {
        return $this->startsAt()->subMinutes($this->event->check_in_window_minutes);
    }
}
