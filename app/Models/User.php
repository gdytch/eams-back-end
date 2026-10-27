<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'email_verified_at', 'organization_id', 'role', 'first_name', 'middle_name', 'last_name', 'photo_paths', 'invite_token', 'invited_at', 'pending_registration_event_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'photo_paths' => 'array',
            'invited_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function accessibleEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_checker_access');
    }

    /**
     * The user's most recent per-event Attendee, the source for prefilling a new registration.
     * Attendee accounts have no organization, so the organization scope must not apply.
     */
    /** @return HasOne<Attendee, $this> */
    public function attendee(): HasOne
    {
        return $this->hasOne(Attendee::class)->withoutGlobalScope('organization')->latestOfMany();
    }

    /** @return HasMany<Attendee, $this> */
    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class);
    }

    /**
     * Invite token of the open event whose registration the Attendee has not completed, or null.
     * Covers an unfinished registration, and a wizard opened before its first save (no Attendee yet).
     */
    public function pendingRegistrationToken(): ?string
    {
        if (! $this->isAttendee()) {
            return null;
        }

        $unfinished = $this->attendees()
            ->withoutGlobalScopes()
            ->whereNull('registration_completed_at')
            ->whereHas('event', fn ($q) => $q->withoutGlobalScopes()->whereNull('archived_at')->whereNotNull('invite_token'))
            ->with(['event' => fn ($q) => $q->withoutGlobalScopes()])
            ->latest('id')
            ->first();

        if ($unfinished !== null) {
            return $unfinished->event->invite_token;
        }

        $event = $this->pending_registration_event_id === null ? null : Event::withoutGlobalScopes()
            ->whereKey($this->pending_registration_event_id)
            ->whereNull('archived_at')
            ->whereNotNull('invite_token')
            ->first();

        $registered = $event !== null && $this->attendees()->withoutGlobalScopes()
            ->where('event_id', $event->id)->whereNotNull('registration_completed_at')->exists();

        return $event === null || $registered ? null : $event->invite_token;
    }

    public function syncNameFrom(Attendee $attendee): void
    {
        $this->update([
            'name' => trim(collect([$attendee->first_name, $attendee->middle_name, $attendee->last_name])->filter()->implode(' ')),
            'first_name' => $attendee->first_name,
            'middle_name' => $attendee->middle_name,
            'last_name' => $attendee->last_name,
        ]);
    }

    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isOrgAdmin(): bool
    {
        return $this->role === UserRole::OrgAdmin;
    }

    public function isChecker(): bool
    {
        return $this->role === UserRole::Checker;
    }

    public function isAttendee(): bool
    {
        return $this->role === UserRole::Attendee;
    }

    public function getHasAttendeeAccountAttribute(): bool
    {
        return $this->attendees()->exists();
    }

    public function hasAccessToEvent(Event $event): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->organization_id !== $event->organization_id) {
            return false;
        }

        if ($this->isOrgAdmin()) {
            return true;
        }

        return $this->isChecker()
            && (! $this->accessibleEvents()->exists()
                || $this->accessibleEvents()->whereKey($event->getKey())->exists());
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function getPhotoUrlsAttribute(): ?array
    {
        return $this->photo_paths
            ? collect($this->photo_paths)->mapWithKeys(fn ($path, $key) => [$key => URL::temporarySignedRoute('profile-photos.show', now()->addMinutes(30), ['kind' => 'users', 'id' => $this->id, 'size' => $key])])->toArray()
            : null;
    }

    public static function generateUniqueInviteToken(): string
    {
        do {
            $token = Str::random(40);
        } while (static::withoutGlobalScopes()->where('invite_token', $token)->exists());

        return $token;
    }

    public function isPendingInvite(): bool
    {
        return $this->invite_token !== null;
    }
}
