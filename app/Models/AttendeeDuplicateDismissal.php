<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'attendee_one_id', 'attendee_two_id', 'name_fingerprint', 'dismissed_by', 'dismissed_at'])]
class AttendeeDuplicateDismissal extends Model
{
    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    public function attendeeOne(): BelongsTo
    {
        return $this->belongsTo(Attendee::class, 'attendee_one_id');
    }

    public function attendeeTwo(): BelongsTo
    {
        return $this->belongsTo(Attendee::class, 'attendee_two_id');
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}
