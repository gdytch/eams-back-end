<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['organization_id', 'name', 'description', 'start_date', 'end_date', 'venue', 'status', 'requires_check_out', 'check_in_window_minutes', 'id_card_background_path', 'id_card_font_color', 'banner_paths', 'speakers_public', 'created_by', 'invite_token'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use BelongsToOrganization, HasFactory;

    protected $attributes = [
        'status' => 'draft',
        'requires_check_out' => false,
        'check_in_window_minutes' => 30,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->invite_token ??= static::generateUniqueInviteToken();
        });
    }

    /**
     * Generate a unique, non-guessable invite token for the event.
     */
    public static function generateUniqueInviteToken(): string
    {
        do {
            $token = Str::random(10);
        } while (static::withoutGlobalScopes()->where('invite_token', $token)->exists());

        return $token;
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => EventStatus::class,
            'requires_check_out' => 'boolean',
            'check_in_window_minutes' => 'integer',
            'banner_paths' => 'array',
            'speakers_public' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The organization a public dropdown is limited to, from the event invite token. Null when no token is given.
     */
    public static function organizationIdForInvite(?string $inviteToken): ?int
    {
        return $inviteToken === null || $inviteToken === ''
            ? null
            : static::withoutGlobalScopes()->where('invite_token', $inviteToken)->firstOrFail()->organization_id;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class);
    }

    public function program(): HasOne
    {
        return $this->hasOne(EventProgram::class);
    }

    /** @return HasMany<Attendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class);
    }

    /**
     * Same rows as attendees(). Scoped route binding for `events/{event}/registrations/{registration}` resolves through this name.
     *
     * @return HasMany<Attendee, $this>
     */
    public function registrations(): HasMany
    {
        return $this->attendees();
    }

    public function speakers(): HasMany
    {
        return $this->hasMany(Speaker::class)->orderBy('name')->orderBy('id');
    }

    public function gridDownloads(): HasMany
    {
        return $this->hasMany(IdCardGridDownload::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_checker_access');
    }

    public function getBannerUrlsAttribute(): array
    {
        return array_map(fn ($path) => $path ? asset("storage/{$path}") : null, $this->banner_paths ?? []);
    }
}
