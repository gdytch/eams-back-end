<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['event_id', 'attendee_id', 'qr_token', 'registered_by', 'registered_at'])]
class EventRegistration extends Model
{
    /** @use HasFactory<\Database\Factories\EventRegistrationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $registration) {
            $registration->qr_token ??= static::generateUniqueQrToken();
            $registration->registered_at ??= now();
        });
    }

    /**
     * An opaque, non-guessable token; never derived from the attendee ID and never reused.
     */
    public static function generateUniqueQrToken(): string
    {
        do {
            $token = Str::random(48);
        } while (static::withoutGlobalScopes()->where('qr_token', $token)->exists());

        return $token;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
