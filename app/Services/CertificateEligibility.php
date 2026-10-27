<?php

namespace App\Services;

use App\Enums\CertificateReleaseRule;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Certificate;
use App\Models\EventSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may receive a Certificate right now. Decided live from the release time, the release rule and the
 * attendance records; nothing is stored. Attendance follows the shared rule: Present = checked in to the slot,
 * Expected = fully registered before the slot ended (breakouts: and holding a pick), Upcoming slots are ignored.
 *
 * Audience membership (hidden leavers) is a separate question, answered by CertificateAudience.
 */
class CertificateEligibility
{
    /** A Certificate is Released once published and its release time, if any, has passed. */
    public function isReleased(Certificate $certificate): bool
    {
        return $certificate->isPublished()
            && ($certificate->release_at === null || $certificate->release_at->lte(now()));
    }

    /** Whether the Attendee meets the rule (or was hand-picked). Release is checked separately. */
    public function isEligible(Certificate $certificate, Attendee $attendee): bool
    {
        return $this->eligibleIds($certificate, collect([$attendee]))->isNotEmpty();
    }

    /**
     * Bulk form: the ids of the given Attendees who meet the rule (or were hand-picked). Release is checked separately.
     *
     * @param  Collection<int, Attendee>  $attendees
     * @return Collection<int, int>
     */
    public function eligibleIds(Certificate $certificate, Collection $attendees): Collection
    {
        $picked = collect($certificate->audience_attendee_ids ?? [])->map(fn ($id) => (int) $id);
        $handPicked = $attendees->pluck('id')->intersect($picked);
        // The rules apply to Fully registered Attendees only.
        $rest = $attendees->whereNotNull('registration_completed_at')->whereNotIn('id', $handPicked->all());

        $byRule = match ($certificate->release_rule) {
            CertificateReleaseRule::Everyone => $rest->pluck('id'),
            CertificateReleaseRule::CheckedIn => $this->checkedIn($certificate, $rest),
            CertificateReleaseRule::Rate50, CertificateReleaseRule::Rate75 => $this->meetingRate($certificate, $rest, (int) $certificate->release_rule->percent()),
        };

        return $handPicked->merge($byRule)->unique()->values();
    }

    /**
     * @param  Collection<int, Attendee>  $attendees
     * @return Collection<int, int>
     */
    private function checkedIn(Certificate $certificate, Collection $attendees): Collection
    {
        if ($attendees->isEmpty()) {
            return collect();
        }

        return $this->countedRecords($certificate, $attendees->pluck('id'))
            ->distinct()->pluck('attendee_id')->map(fn ($id) => (int) $id);
    }

    /**
     * The one definition of which check-ins count toward a Certificate. A session check-in made through a breakout
     * (via_breakout_id) is a session check-in, as in the attendance report; breakout slots count only with the flag.
     *
     * @param  Collection<int, int>  $attendeeIds
     * @return Builder<AttendanceRecord>
     */
    private function countedRecords(Certificate $certificate, Collection $attendeeIds): Builder
    {
        return AttendanceRecord::query()
            ->whereIn('attendee_id', $attendeeIds)
            ->whereNotNull('check_in_at')
            ->when(! $certificate->include_breakouts, fn (Builder $q) => $q->whereNull('breakout_id'));
    }

    /**
     * Present slots over Expected slots that have ended, capped at 100%; at least $percent qualifies.
     * Walk-ins and late registrants add to the numerator only.
     *
     * @param  Collection<int, Attendee>  $attendees
     * @return Collection<int, int>
     */
    private function meetingRate(Certificate $certificate, Collection $attendees, int $percent): Collection
    {
        if ($attendees->isEmpty()) {
            return collect();
        }

        $ids = $attendees->pluck('id');
        $sessions = EventSession::query()->where('event_id', $certificate->event_id)->get()->filter->hasEnded();
        $breakouts = $certificate->include_breakouts
            ? Breakout::withoutGlobalScopes()->where('event_id', $certificate->event_id)->get()->filter->hasEnded()
            : collect();

        $records = $this->countedRecords($certificate, $ids)->get()->groupBy('attendee_id');
        $picks = $breakouts->isEmpty() ? collect() : DB::table('breakout_picks')
            ->whereIn('attendee_id', $ids)->whereIn('breakout_id', $breakouts->modelKeys())
            ->get()->groupBy('attendee_id')->map(fn ($rows) => $rows->pluck('breakout_id')->map(fn ($id) => (int) $id));

        return $attendees->filter(function (Attendee $attendee) use ($sessions, $breakouts, $records, $picks, $percent): bool {
            $mine = $records->get($attendee->id, collect());
            $presentSessions = $mine->pluck('event_session_id')->filter()->all();
            $presentBreakouts = $mine->pluck('breakout_id')->filter()->all();
            $picked = $picks->get($attendee->id, collect());

            $expectedSessions = $sessions->filter(fn (EventSession $s) => $s->isExpected($attendee->first_registered_at));
            $expectedBreakouts = $breakouts->filter(fn (Breakout $b) => $b->isExpected($attendee->first_registered_at) && $picked->contains($b->id));

            $expected = $expectedSessions->count() + $expectedBreakouts->count();
            $present = $sessions->whereIn('id', $presentSessions)->count() + $breakouts->whereIn('id', $presentBreakouts)->count();

            // present/expected (capped at 100%) >= percent/100, in integers.
            return $expected > 0 && min($present, $expected) * 100 >= $percent * $expected;
        })->pluck('id')->map(fn ($id) => (int) $id)->values();
    }
}
