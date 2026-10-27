<?php

namespace App\Jobs;

use App\Enums\IssuedCertificateStatus;
use App\Models\Certificate;
use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Bus;

/**
 * Starts the background generation of a published Certificate: splits its pending Issued certificates into
 * batches, like the ID card grid download. Exits quietly when the run is stale or the event is archived.
 */
class GenerateCertificateJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    private const PER_BATCH = 25;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $certificateId,
        public int $run,
    ) {}

    public function handle(): void
    {
        $certificate = self::activeCertificate($this->certificateId, $this->run);
        if ($certificate === null) {
            return;
        }

        $jobs = $certificate->issuedCertificates()
            ->where('status', IssuedCertificateStatus::Pending)
            ->orderBy('id')
            ->pluck('id')
            ->chunk(self::PER_BATCH)
            ->map(fn ($ids) => new GenerateIssuedCertificatesBatchJob($this->certificateId, $this->run, $ids->values()->all()))
            ->all();

        if ($jobs !== []) {
            Bus::batch($jobs)->name("Certificate #{$this->certificateId} generation")->dispatch();
        }
    }

    /** The Certificate when this run may still write: it is published in this run and its event is not archived. */
    public static function activeCertificate(int $certificateId, int $run): ?Certificate
    {
        $certificate = Certificate::withoutGlobalScopes()->find($certificateId);
        if ($certificate === null || ! $certificate->isPublished() || $certificate->generation_run !== $run) {
            return null;
        }

        $event = Event::withoutGlobalScopes()->find($certificate->event_id);

        return $event === null || $event->isArchived() ? null : $certificate;
    }
}
