<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'name', 'code'])]
class Union extends Model
{
    /** @use HasFactory<\Database\Factories\UnionFactory> */
    use BelongsToOrganization, HasFactory;

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class);
    }
}
