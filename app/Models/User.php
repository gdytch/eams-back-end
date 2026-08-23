<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'organization_id', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
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
}
