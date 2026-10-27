<?php

namespace App\Services;

use App\Enums\OrganizationLevel;
use App\Models\Attendee;
use App\Models\Certificate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves a Certificate's Audience: the Fully registered Attendees of its event matching the selected
 * unions and missions by Organization level, plus hand-picked Attendees. Nothing selected means everyone.
 * Eligibility (release rule, Attendance rate) is deliberately not decided here.
 */
class CertificateAudience
{
    /**
     * @return Builder<Attendee>
     */
    public function query(Certificate $certificate): Builder
    {
        $unions = $certificate->audience_union_ids ?? [];
        $missions = $certificate->audience_mission_ids ?? [];
        $picked = $certificate->audience_attendee_ids ?? [];

        return Attendee::query()
            ->where('attendees.event_id', $certificate->event_id)
            ->withRegistrationStatus('registered')
            ->when($unions || $missions || $picked, fn (Builder $query) => $query->where(fn (Builder $any) => $any
                ->when($unions, fn (Builder $q) => $q->orWhere(fn (Builder $level) => $level
                    ->where('attendees.organization_level', OrganizationLevel::Union)
                    ->whereIn('attendees.union_id', $unions)))
                ->when($missions, fn (Builder $q) => $q->orWhere(fn (Builder $level) => $level
                    ->where('attendees.organization_level', OrganizationLevel::Mission)
                    ->whereIn('attendees.mission_id', $missions)))
                ->when($picked, fn (Builder $q) => $q->orWhereIn('attendees.id', $picked))));
    }

    public function count(Certificate $certificate): int
    {
        return $this->query($certificate)->count();
    }
}
