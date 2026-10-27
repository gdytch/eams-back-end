<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\IssuedCertificateStatus;
use App\Jobs\GenerateIssuedCertificatesBatchJob;
use App\Models\Attendee;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\IssuedCertificate;

/**
 * Keeps the Issued certificates of published Certificates in step with the Audience after publish: a newly
 * Fully registered or moved Attendee gets a missing file queued, a leaver's file is hidden (kept), and a renamed
 * Attendee's file is regenerated. Draft Certificates and archived events are never touched.
 */
class CertificateAudienceSync
{
    public function __construct(private CertificateAudience $audience) {}

    public function sync(Attendee $attendee, bool $nameChanged = false): void
    {
        $event = Event::withoutGlobalScopes()->find($attendee->event_id);
        if ($event === null || $event->isArchived()) {
            return;
        }

        Certificate::withoutGlobalScopes()
            ->where('event_id', $event->id)
            ->where('status', CertificateStatus::Published)
            ->each(fn (Certificate $certificate) => $this->syncCertificate($certificate, $attendee, $nameChanged));
    }

    private function syncCertificate(Certificate $certificate, Attendee $attendee, bool $nameChanged): void
    {
        $inAudience = $this->audience->query($certificate)->whereKey($attendee->id)->exists();
        $issued = IssuedCertificate::query()
            ->where('certificate_id', $certificate->id)
            ->where('attendee_id', $attendee->id)
            ->first();

        if (! $inAudience) {
            if ($issued !== null) {
                // The kept file carries the old name; mark it for regeneration should the Attendee return.
                $issued->hidden_at ??= now();
                if ($nameChanged) {
                    $issued->status = IssuedCertificateStatus::Pending;
                }
                $issued->save();
            }

            return;
        }

        $issued ??= new IssuedCertificate([
            'certificate_id' => $certificate->id,
            'attendee_id' => $attendee->id,
            'status' => IssuedCertificateStatus::Pending,
        ]);
        if ($nameChanged) {
            $issued->status = IssuedCertificateStatus::Pending;
        }
        $issued->hidden_at = null;
        $issued->save();

        if ($issued->status === IssuedCertificateStatus::Pending) {
            GenerateIssuedCertificatesBatchJob::dispatch($certificate->id, $certificate->generation_run, [$issued->id])->afterCommit();
        }
    }
}
