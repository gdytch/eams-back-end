<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\IssuedCertificate;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The Issued certificates of one event an Attendee user may see now: published, Released, Eligible, still in the
 * Audience (a leaver's row is hidden), with a stored file. Only the user's Linked Attendees are ever consulted.
 */
class AttendeeCertificates
{
    public function __construct(private CertificateEligibility $eligibility, private CertificateAudience $audience) {}

    /**
     * One query loads every candidate row; the Audience and eligibility checks run once per Certificate.
     *
     * @return Collection<int, IssuedCertificate>
     */
    public function availableFor(User $user, int $eventId): Collection
    {
        return $this->availableForEvents($user, [Event::withoutGlobalScopes()->findOrFail($eventId)->id]);
    }

    /**
     * The same rows as availableFor() for several events, loading Attendees, Certificates and file-holding rows once
     * for all of them. Ordered by event id, then Certificate id; events the user has not joined contribute nothing.
     *
     * @param  array<int, int>  $eventIds
     * @return Collection<int, IssuedCertificate>
     */
    public function availableForEvents(User $user, array $eventIds): Collection
    {
        $attendees = $user->attendees()->withoutGlobalScopes()->whereNull('merged_at')->whereIn('event_id', $eventIds)->get();
        if ($attendees->isEmpty()) {
            return collect();
        }

        $byEvent = $attendees->groupBy('event_id');
        $certificates = $this->releasedCertificates($byEvent->keys()->all());
        $issued = $this->fileHolders($certificates, $attendees);

        return $certificates
            ->map(function (Certificate $certificate) use ($byEvent, $issued) {
                $eventAttendees = $byEvent->get($certificate->event_id, collect());
                $mine = $issued->get($certificate->id, collect())->whereIn('attendee_id', $eventAttendees->modelKeys())->keyBy('attendee_id');

                return $this->eligibleRow($certificate, $eventAttendees, $mine);
            })
            ->filter()->values();
    }

    /**
     * @param  array<int, int>  $eventIds
     * @return Collection<int, Certificate>
     */
    private function releasedCertificates(array $eventIds): Collection
    {
        return Certificate::withoutGlobalScopes()->whereIn('event_id', $eventIds)->orderBy('event_id')->orderBy('id')->get()
            ->filter(fn (Certificate $certificate) => $this->eligibility->isReleased($certificate));
    }

    /**
     * Visible rows with a stored file, grouped by Certificate id.
     *
     * @param  Collection<int, Certificate>  $certificates
     * @param  Collection<int, Attendee>  $attendees
     * @return Collection<int, Collection<int, IssuedCertificate>>
     */
    private function fileHolders(Collection $certificates, Collection $attendees): Collection
    {
        return IssuedCertificate::query()->visible()
            ->whereIn('certificate_id', $certificates->modelKeys())
            ->whereIn('attendee_id', $attendees->modelKeys())
            ->get()->filter->hasFile()->groupBy('certificate_id');
    }

    /**
     * @param  Collection<int, Attendee>  $attendees
     * @param  Collection<int, IssuedCertificate>  $mine  the caller's file-holding rows of this Certificate, by Attendee id
     */
    private function eligibleRow(Certificate $certificate, Collection $attendees, Collection $mine): ?IssuedCertificate
    {
        if ($mine->isEmpty()) {
            return null;
        }

        // Audience membership stays a live check (one query per Certificate the caller holds a file of).
        $inAudience = $this->audience->query($certificate)->withoutGlobalScope('organization')
            ->whereIn('attendees.id', $mine->keys()->all())->pluck('attendees.id')->all();
        $eligibleId = $this->eligibility->eligibleIds($certificate, $attendees->whereIn('id', $inAudience))->first();
        if ($eligibleId === null) {
            return null;
        }

        return $mine[$eligibleId]->setRelation('certificate', $certificate)->setRelation('attendee', $attendees->firstWhere('id', $eligibleId));
    }
}
