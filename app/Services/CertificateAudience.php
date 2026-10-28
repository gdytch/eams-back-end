<?php

namespace App\Services;

use App\Enums\OrganizationLevel;
use App\Models\Attendee;
use App\Models\Certificate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a Certificate's Audience: the Fully registered Attendees of its event matching the selected
 * unions and missions by Organization level, Attendees who picked a selected Breakout, plus hand-picked Attendees.
 * Nothing selected means everyone. Picks are read from the raw breakout_picks table so the Breakout organization
 * scope cannot hide them from an Attendee user. Eligibility (release rule, Attendance rate) is deliberately not decided here.
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
        $breakouts = $certificate->audience_breakout_ids ?? [];

        return Attendee::query()
            ->where('attendees.event_id', $certificate->event_id)
            ->withRegistrationStatus('registered')
            ->unless($certificate->audienceIsEveryone(), fn (Builder $query) => $query->where(fn (Builder $any) => $any
                ->when($unions, fn (Builder $q) => $q->orWhere(fn (Builder $level) => $level
                    ->where('attendees.organization_level', OrganizationLevel::Union)
                    ->whereIn('attendees.union_id', $unions)))
                ->when($missions, fn (Builder $q) => $q->orWhere(fn (Builder $level) => $level
                    ->where('attendees.organization_level', OrganizationLevel::Mission)
                    ->whereIn('attendees.mission_id', $missions)))
                ->when($breakouts, fn (Builder $q) => $q->orWhereIn('attendees.id', DB::table('breakout_picks')
                    ->select('attendee_id')->whereIn('breakout_id', $breakouts)))
                ->when($picked, fn (Builder $q) => $q->orWhereIn('attendees.id', $picked))));
    }

    public function count(Certificate $certificate): int
    {
        return $this->query($certificate)->count();
    }
}
