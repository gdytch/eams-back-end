<?php

namespace App\Jobs;

use App\Enums\IssuedCertificateStatus;
use App\Models\Attendee;
use App\Models\IssuedCertificate;
use App\Services\CertificateRenderer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Renders and stores a batch of Issued certificates. One failing Attendee is recorded and does not stop the rest. */
class GenerateIssuedCertificatesBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param  list<int>  $issuedCertificateIds */
    public function __construct(
        public int $certificateId,
        public int $run,
        public array $issuedCertificateIds,
    ) {}

    public function handle(CertificateRenderer $renderer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $certificate = GenerateCertificateJob::activeCertificate($this->certificateId, $this->run);
        if ($certificate === null) {
            return;
        }

        $issued = IssuedCertificate::query()
            ->where('certificate_id', $certificate->id)
            ->whereIn('id', $this->issuedCertificateIds)
            ->where('status', IssuedCertificateStatus::Pending)
            ->get();

        foreach ($issued as $row) {
            // A later row must not write once the Certificate is unpublished or its event archived.
            if (GenerateCertificateJob::activeCertificate($this->certificateId, $this->run) === null) {
                return;
            }

            try {
                $attendee = Attendee::withoutGlobalScopes()->findOrFail($row->attendee_id);
                $rendered = $renderer->render($certificate, $attendee);
                $path = "events/{$certificate->event_id}/certificates/{$certificate->id}/{$row->attendee_id}.pdf";
                Storage::disk('local')->put($path, $rendered->pdf);

                $row->update([
                    'status' => IssuedCertificateStatus::Generated,
                    'failure_reason' => null,
                    'name_too_long' => $rendered->nameTooLong,
                    'file_path' => $path,
                    'generated_at' => now(),
                    'file_deleted_at' => null,
                ]);
            } catch (Throwable $e) {
                Log::warning('Issued certificate generation failed', [
                    'certificate_id' => $certificate->id, 'attendee_id' => $row->attendee_id, 'error' => $e->getMessage(),
                ]);
                $row->update([
                    'status' => IssuedCertificateStatus::Failed,
                    'failure_reason' => Str::limit($e->getMessage(), 480, ''),
                ]);
            }
        }
    }
}
