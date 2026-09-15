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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'email_verified_at', 'organization_id', 'role', 'first_name', 'middle_name', 'last_name', 'photo_paths', 'invite_token', 'invited_at'])]
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

    public function attendee(): HasOne
    {
        return $this->hasOne(Attendee::class);
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
        if ($this->attendee())
            return true;
        return $this->role === UserRole::Attendee;
    }

    /**
     * Super Admins and Org Admins always have full access; a Checker with zero
     * `event_checker_access` rows also has full org access by default (only
     * gains explicit rows to become restricted to specific events).
     */
    public function hasAccessToEvent(Event $event): bool
    {
        if ($this->isSuperAdmin() || $this->isOrgAdmin()) {
            return true;
        }

        if (! $this->accessibleEvents()->exists()) {
            return true;
        }

        return $this->accessibleEvents()->whereKey($event->getKey())->exists();
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
            ? collect($this->photo_paths)->mapWithKeys(fn($path, $key) => [$key => Storage::disk('public')->url($path)])->toArray()
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
