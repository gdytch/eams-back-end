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

#[Fillable(['organization_id', 'name', 'description', 'start_date', 'end_date', 'venue', 'status', 'requires_check_out', 'check_in_window_minutes', 'id_card_background_path', 'id_card_font_color', 'banner_paths', 'created_by', 'invite_token'])]
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
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class);
    }

    public function program(): HasOne
    {
        return $this->hasOne(EventProgram::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
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
