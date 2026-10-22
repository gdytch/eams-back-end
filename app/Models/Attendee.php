<?php

namespace App\Models;

use App\Enums\OrganizationLevel;
use App\Models\Concerns\BelongsToOrganization;
use App\Observers\AttendeeObserver;
use App\Services\RegistrationCompleteness;
use Database\Factories\AttendeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * @property int|null $event_id
 * @property string|null $qr_token
 * @property string|null $id_card_path
 * @property array<int, string>|null $id_card_images_path
 * @property Carbon|null $id_card_generated_at
 * @property int|null $registered_by
 * @property Carbon|null $registered_at
 * @property int|null $legacy_registration_id
 * @property Carbon|null $registration_confirmed_at
 * @property Carbon|null $registration_completed_at
 * @property Carbon|null $first_registered_at
 */
#[ObservedBy(AttendeeObserver::class)]
#[Fillable(['organization_id', 'event_id', 'qr_token', 'id_card_path', 'id_card_images_path', 'id_card_generated_at', 'registered_by', 'registered_at', 'registration_confirmed_at', 'registration_completed_at', 'first_registered_at', 'organization_level', 'union_id', 'mission_id', 'church_id', 'first_name', 'middle_name', 'last_name', 'mobile_no', 'email_address', 'remarks', 'photo_paths', 'created_by', 'user_id', 'invite_token', 'invited_at', 'merged_into_id', 'merged_by', 'merged_at'])]
class Attendee extends Model
{
    /** @use HasFactory<AttendeeFactory> */
    use BelongsToOrganization, HasFactory;

    protected function casts(): array
    {
        return [
            'organization_level' => OrganizationLevel::class,
            'photo_paths' => 'array',
            'invited_at' => 'datetime',
            'merged_at' => 'datetime',
            'registered_at' => 'datetime',
            'registration_confirmed_at' => 'datetime',
            'registration_completed_at' => 'datetime',
            'first_registered_at' => 'datetime',
            'picks_saved_at' => 'datetime',
            'picks_released_at' => 'datetime',
            'id_card_generated_at' => 'datetime',
            'id_card_images_path' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('active', function (Builder $builder) {
            $builder->whereNull($builder->getModel()->qualifyColumn('merged_at'));
        });

        static::saving(function (self $attendee) {
            // Never trust client input: the organization always follows the event.
            if ($attendee->event_id !== null) {
                $attendee->organization_id = Event::withoutGlobalScopes()->whereKey($attendee->event_id)->value('organization_id');
                app(RegistrationCompleteness::class)->apply($attendee);
            }

            $attendee->normalized_name = static::normalizeName(
                $attendee->first_name,
                $attendee->last_name,
            );
        });
    }

    /**
     * An opaque, non-guessable token; never derived from the attendee ID and never reused.
     */
    public static function generateUniqueQrToken(int $eventId): string
    {
        do {
            $token = $eventId.Str::random(24);
        } while (static::withoutGlobalScopes()->where('qr_token', 'like', '%/'.$token)->exists());

        return config('app.CLIENT_URL').'/attendee/'.$token;
    }

    /**
     * Normalize a name for duplicate comparison: trim, lowercase, collapse whitespace.
     */
    public static function normalizeName(?string $first, ?string $last): string
    {
        return collect([$first, $last])
            ->filter()
            ->map(fn (string $part) => preg_replace('/\s+/', ' ', trim(mb_strtolower($part))))
            ->implode(' ');
    }

    /**
     * Generate a unique, non-guessable invite token for staff-created attendees.
     */
    public static function generateUniqueInviteToken(): string
    {
        do {
            $token = Str::random(40);
        } while (static::withoutGlobalScopes()->where('invite_token', $token)->exists());

        return $token;
    }

    /**
     * Link that lets an Attendee without a login create an account; null until an invite token is issued.
     */
    public function inviteUrl(): ?string
    {
        return $this->invite_token === null ? null : config('app.frontend_url').'/register?invite='.$this->invite_token.'&email='.urlencode($this->email_address);
    }

    public static function matchingName(?string $first, ?string $last, ?int $organizationId = null, ?int $exceptId = null, ?int $eventId = null): Collection
    {
        $name = static::normalizeName($first, $last);

        return static::query()
            ->when($eventId, fn (Builder $query) => $query->where('event_id', $eventId))
            ->when($organizationId, fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->cursor()
            ->filter(fn (self $attendee) => static::normalizeName($attendee->first_name, $attendee->last_name) === $name)
            ->collect();
    }

    /**
     * Filter by registration status: draft, incomplete or registered.
     *
     * @param  Builder<Attendee>  $query
     */
    public function scopeWithRegistrationStatus(Builder $query, string $status): void
    {
        match ($status) {
            'registered' => $query->whereNotNull('registration_completed_at'),
            'incomplete' => $query->whereNull('registration_completed_at')->whereNotNull('first_registered_at'),
            'draft' => $query->whereNull('first_registered_at'),
        };
    }

    /**
     * Expected at a slot: fully registered for the first time before it ended.
     *
     * @param  Builder<Attendee>  $query
     */
    public function scopeExpectedAt(Builder $query, EventSession|Breakout $slot): void
    {
        $query->where('attendees.first_registered_at', '<', $slot->endsAt())
            // At a breakout only current pick holders are Expected; walk-ins never are.
            ->when($slot instanceof Breakout, fn (Builder $q) => $q->whereHas('breakouts', fn ($picks) => $picks->whereKey($slot->id)));
    }

    /**
     * @param  Builder<Attendee>  $query
     */
    public function scopePresentAt(Builder $query, EventSession|Breakout $slot): void
    {
        $query->whereHas('attendanceRecords', fn ($records) => $records
            ->where(AttendanceRecord::slotColumn($slot), $slot->id)
            ->whereNotNull('check_in_at'));
    }

    /**
     * Expected at a slot with no check-in: Absent once it ended, Upcoming before.
     *
     * @param  Builder<Attendee>  $query
     */
    public function scopeNotCheckedInAt(Builder $query, EventSession|Breakout $slot): void
    {
        $query->expectedAt($slot)->whereDoesntHave('attendanceRecords', fn ($records) => $records
            ->where(AttendanceRecord::slotColumn($slot), $slot->id)
            ->whereNotNull('check_in_at'));
    }

    /**
     * Who attendance reports list for a slot: Expected Attendees plus anyone checked in (walk-ins).
     *
     * @param  Builder<Attendee>  $query
     */
    public function scopeInAttendanceOf(Builder $query, EventSession|Breakout $slot): void
    {
        $query->where(fn ($q) => $q->expectedAt($slot)->orWhere(fn ($p) => $p->presentAt($slot)));
    }

    public function registrationStatus(): ?string
    {
        return match (true) {
            $this->event_id === null => null,
            $this->registration_completed_at !== null => 'registered',
            $this->first_registered_at !== null => 'incomplete',
            default => 'draft',
        };
    }

    public function union(): BelongsTo
    {
        return $this->belongsTo(Union::class);
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class);
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Every per-event Attendee row of this person (linked through the user account), including this one.
     */
    public function personRegistrations(): Builder
    {
        return static::query()->where(fn (Builder $query) => $query
            ->whereKey($this->id)
            ->when($this->user_id, fn (Builder $query) => $query->orWhere('user_id', $this->user_id)));
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsToMany<Breakout, $this> */
    public function breakouts(): BelongsToMany
    {
        return $this->belongsToMany(Breakout::class, 'breakout_picks')->withPivot('overbooked')->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /** @return HasMany<AttendanceRecord, $this> */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function getIDCardImageAttribute(): ?string
    {
        if (empty($this->id_card_images_path)) {
            return null;
        }

        return route('events.registrations.id-card-image', [
            'event' => $this->event_id,
            'registration' => $this->id,
        ]);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function mergedSources(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

    public function getFullNameAttribute()
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    public function getPhotoUrlsAttribute()
    {
        return $this->photo_paths
            ? collect($this->photo_paths)->mapWithKeys(fn ($path, $key) => [
                $key => URL::temporarySignedRoute('profile-photos.show', now()->addMinutes(30), ['kind' => 'attendees', 'id' => $this->id, 'size' => $key]),
            ])->toArray()
            : null;
    }

    public function getTerritoryAttribute()
    {
        $unionName = $this->union ? $this->union->name.', ' : '';
        $missionName = $this->mission ? $this->mission->name.', ' : '';
        $churchName = $this->church ? $this->church->name : '';

        return trim("{$unionName}{$missionName}{$churchName}");
    }

    /**
     * The organization (union or mission) referenced by organization_level, if any.
     */
    public function getOrganizationLevelReferenceAttribute(): Union|Mission|null
    {
        return match ($this->organization_level) {
            OrganizationLevel::Union => $this->union,
            OrganizationLevel::Mission => $this->mission,
            default => null,
        };
    }

    public function getOrganizationLevelNameAttribute(): ?string
    {
        return match ($this->organization_level) {
            OrganizationLevel::Union => $this->union?->name,
            OrganizationLevel::Mission => $this->mission?->name,
            default => null,
        };
    }

    public function getOrganizationLevelCodeAttribute(): ?string
    {
        return match ($this->organization_level) {
            OrganizationLevel::Union => $this->union?->code,
            OrganizationLevel::Mission => $this->mission?->code,
            default => null,
        };
    }
}
