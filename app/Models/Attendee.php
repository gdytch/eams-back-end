<?php

namespace App\Models;

use App\Enums\OrganizationLevel;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AttendeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable(['organization_id', 'organization_level', 'union_id', 'mission_id', 'church_id', 'first_name', 'middle_name', 'last_name', 'mobile_no', 'email_address', 'remarks', 'photo_paths', 'created_by', 'user_id', 'invite_token', 'invited_at'])]
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
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $attendee) {
            $attendee->normalized_name = static::normalizeName(
                $attendee->first_name,
                $attendee->middle_name,
                $attendee->last_name,
            );
        });
    }

    /**
     * Normalize a name for duplicate comparison: trim, lowercase, collapse whitespace.
     */
    public static function normalizeName(?string $first, ?string $middle, ?string $last): string
    {
        return collect([$first, $middle, $last])
            ->filter()
            ->map(fn(string $part) => preg_replace('/\s+/', ' ', trim(mb_strtolower($part))))
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

    #[Scope]
    protected function matchingName(Builder $query, ?string $first, ?string $middle, ?string $last): Builder
    {
        return $query->where('normalized_name', static::normalizeName($first, $middle, $last));
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

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function getFullNameAttribute()
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }

    public function getPhotoUrlsAttribute()
    {
        return $this->photo_paths
            ? collect($this->photo_paths)->mapWithKeys(fn($path, $key) => [
                $key => Storage::disk('public')->url($path),
            ])->toArray()
            : null;
    }

    public function getTerritoryAttribute()
    {
        $unionName = $this->union ? $this->union->name . ', ' : '';
        $missionName = $this->mission ? $this->mission->name . ', ' : '';
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
}
