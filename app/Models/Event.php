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

#[Fillable(['organization_id', 'name', 'description', 'start_date', 'end_date', 'venue', 'status', 'requires_check_out', 'check_in_window_minutes', 'created_by'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use BelongsToOrganization, HasFactory;

    protected $attributes = [
        'status' => 'draft',
        'requires_check_out' => false,
        'check_in_window_minutes' => 30,
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => EventStatus::class,
            'requires_check_out' => 'boolean',
            'check_in_window_minutes' => 'integer',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_checker_access');
    }
}
