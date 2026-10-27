<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\IssuedCertificateStatus;
use App\Jobs\GenerateCertificateJob;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\IssuedCertificate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes a draft Certificate: one Issued certificate row per Audience member, all queued for generation.
 * Rows of Attendees no longer in the Audience (kept from an earlier publish) are hidden.
 */
class PublishCertificate
{
    public function __construct(private CertificateAudience $audience, private CertificateAudienceSync $sync) {}

    /** @throws ValidationException When already published, or without a Template or release time. */
    public function __invoke(Certificate $certificate): Certificate
    {
        $count = DB::transaction(function () use ($certificate) {
            // The state is checked under lock, so two concurrent publishes cannot both run.
            $locked = Certificate::withoutGlobalScopes()->lockForUpdate()->findOrFail($certificate->id);
            if ($locked->isPublished()) {
                throw ValidationException::withMessages(['status' => 'This certificate is already published.']);
            }
            if (! $locked->template_path) {
                throw ValidationException::withMessages(['template' => 'Upload a Template before publishing.']);
            }

            if (! $locked->release_at) {
                throw ValidationException::withMessages(['release_at' => 'Set a release date and time before publishing.']);
            }

            $locked->update([
                'status' => CertificateStatus::Published,
                'published_at' => now(),
                'generation_run' => $locked->generation_run + 1,
            ]);

            $count = 0;
            $this->audience->query($locked)->select('attendees.id')->orderBy('attendees.id')->chunkById(500, function ($attendees) use ($locked, &$count) {
                $count += $attendees->count();
                IssuedCertificate::upsert(
                    $attendees->map(fn ($attendee) => [
                        'certificate_id' => $locked->id, 'attendee_id' => $attendee->id,
                        'status' => IssuedCertificateStatus::Pending->value, 'failure_reason' => null, 'name_too_long' => false,
                        'hidden_at' => null, 'created_at' => now(), 'updated_at' => now(),
                    ])->all(),
                    ['certificate_id', 'attendee_id'],
                    ['status', 'failure_reason', 'name_too_long', 'hidden_at', 'updated_at'],
                );
            }, 'attendees.id', 'id');

            $this->sync->hideLeavers($locked);

            return $count;
        });

        $certificate->refresh();
        AuditLog::record('certificate.published', $certificate, ['audience' => $count]);
        GenerateCertificateJob::dispatch($certificate->id, $certificate->generation_run);

        return $certificate;
    }
}
