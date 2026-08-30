<?php

namespace App\Models;

use Database\Factories\EventRegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['event_id', 'attendee_id', 'qr_token', 'id_card_path', 'id_card_images_path', 'id_card_generated_at', 'registered_by', 'registered_at'])]
class EventRegistration extends Model
{
    /** @use HasFactory<EventRegistrationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'id_card_generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $registration) {
            $registration->qr_token ??= static::generateUniqueQrToken($registration->event_id, $registration->attendee_id);
            $registration->registered_at ??= now();
        });
    }

    /**
     * An opaque, non-guessable token; never derived from the attendee ID and never reused.
     */
    public static function generateUniqueQrToken(?int $eventId = null, ?int $attendeeId = null): string
    {
        $event_id = $eventId ?? Event::query()->count() + 1;
        $attendee_id = $attendeeId ?? Attendee::query()->count() + 1;
        do {
            $token = "{$event_id}{$attendee_id}" . Str::random(20);
        } while (static::withoutGlobalScopes()->where('qr_token', $token)->exists());

        return config('app.CLIENT_URL') . '/attendee/' . $token;
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
