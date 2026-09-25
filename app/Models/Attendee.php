<?php

namespace App\Models;

use App\Enums\OrganizationLevel;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AttendeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['organization_id', 'organization_level', 'union_id', 'mission_id', 'church_id', 'first_name', 'middle_name', 'last_name', 'mobile_no', 'email_address', 'remarks', 'photo_paths', 'created_by', 'user_id', 'invite_token', 'invited_at', 'merged_into_id', 'merged_by', 'merged_at'])]
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
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('active', function (Builder $builder) {
            $builder->whereNull($builder->getModel()->qualifyColumn('merged_at'));
        });

        static::saving(function (self $attendee) {
            $attendee->normalized_name = static::normalizeName(
                $attendee->first_name,
                $attendee->last_name,
            );
        });
    }

    /**
     * Normalize a name for duplicate comparison: trim, lowercase, collapse whitespace.
     */
    public static function normalizeName(?string $first, ?string $last): string
    {
        return collect([$first, $last])
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

    public static function matchingName(?string $first, ?string $last, ?int $organizationId = null, ?int $exceptId = null): Collection
    {
        $name = static::normalizeName($first, $last);

        return static::query()
            ->when($organizationId, fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->cursor()
            ->filter(fn (self $attendee) => static::normalizeName($attendee->first_name, $attendee->last_name) === $name)
            ->collect();
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
