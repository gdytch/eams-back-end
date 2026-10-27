<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\MissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'union_id', 'name', 'code'])]
class Mission extends Model
{
    /** Attendee accounts have no organization but read these across all of them. */
    public const VISIBLE_TO_ATTENDEES = true;

    /** @use HasFactory<MissionFactory> */
    use BelongsToOrganization, HasFactory;

    public function union(): BelongsTo
    {
        return $this->belongsTo(Union::class);
    }

    public function churches(): HasMany
    {
        return $this->hasMany(Church::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class);
    }
}
