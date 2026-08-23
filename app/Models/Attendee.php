<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\AttendeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'union_id', 'mission_id', 'first_name', 'middle_name', 'last_name', 'profile_photo_path', 'created_by'])]
class Attendee extends Model
{
    /** @use HasFactory<AttendeeFactory> */
    use BelongsToOrganization, HasFactory;

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
            ->map(fn (string $part) => preg_replace('/\s+/', ' ', trim(mb_strtolower($part))))
            ->implode(' ');
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }
}
