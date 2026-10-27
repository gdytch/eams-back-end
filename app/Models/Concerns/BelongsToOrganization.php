<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Scopes a model to the authenticated user's organization (bypassed for Super Admins)
 * and auto-fills organization_id from the authenticated user when creating.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            $user = Auth::user();

            // Attendee accounts belong to no organization; models that opt in stay readable to them (staff access is policy-gated).
            $readableByAttendees = $user?->isAttendee() && defined(static::class.'::VISIBLE_TO_ATTENDEES');

            if ($user !== null && ! $user->isSuperAdmin() && ! $readableByAttendees) {
                $builder->where($builder->getModel()->qualifyColumn('organization_id'), $user->organization_id);
            }
        });

        static::creating(function (self $model) {
            if ($model->organization_id === null && Auth::check()) {
                $model->organization_id = Auth::user()->organization_id;
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
