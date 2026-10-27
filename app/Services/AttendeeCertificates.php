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
        $eventId = Event::withoutGlobalScopes()->findOrFail($eventId)->id;
        $attendees = $user->attendees()->withoutGlobalScopes()->whereNull('merged_at')->where('event_id', $eventId)->get();
        if ($attendees->isEmpty()) {
            return collect();
        }

        $certificates = $this->releasedCertificates($eventId);
        $issued = $this->fileHolders($certificates, $attendees);

        return $certificates
            ->map(fn (Certificate $certificate) => $this->eligibleRow($certificate, $attendees, $issued->get($certificate->id, collect())->keyBy('attendee_id')))
            ->filter()->values();
    }

    /** @return Collection<int, Certificate> */
    private function releasedCertificates(int $eventId): Collection
    {
        return Certificate::withoutGlobalScopes()->where('event_id', $eventId)->orderBy('id')->get()
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
