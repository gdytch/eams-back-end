<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\Carbon;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['organization_id', 'name', 'description', 'start_date', 'end_date', 'venue', 'status', 'requires_check_out', 'check_in_window_minutes', 'id_card_background_path', 'id_card_font_color', 'banner_paths', 'speakers_public', 'created_by', 'invite_token', 'pick_mode', 'pick_min', 'pick_max'])]
class Event extends Model
{
    /** Days after archiving when form uploads and generated ID cards are deleted. */
    public const FILE_RETENTION_DAYS = 15;

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
            'pick_min' => 'integer',
            'pick_max' => 'integer',
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

    /**
     * Not archived and not yet ended.
     *
     * @param  Builder<Event>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('archived_at')->whereDate('end_date', '>=', today());
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** When the event's private files are deleted, or null while the event is not archived. */
    public function filesDeletionAt(): ?Carbon
    {
        return $this->archived_at?->copy()->addDays(self::FILE_RETENTION_DAYS);
    }

    /**
     * Archived events are hidden from checkers until unarchived; their checker assignments are kept.
     */
    public function isHiddenFrom(User $user): bool
    {
        return $user->isChecker() && $this->isArchived();
    }

    /** @return HasMany<EventSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class);
    }

    /** Required pick count as [min, max|null], or null while breakouts are disabled. */
    public function pickRange(): ?array
    {
        return $this->pick_mode === null ? null : [$this->pick_min, $this->pick_max];
    }

    /** @return HasMany<Form, $this> */
    public function forms(): HasMany
    {
        return $this->hasMany(Form::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Breakout, $this> */
    public function breakouts(): HasMany
    {
        return $this->hasMany(Breakout::class);
    }

    public function program(): HasOne
    {
        return $this->hasOne(EventProgram::class)->whereNull('breakout_id');
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
