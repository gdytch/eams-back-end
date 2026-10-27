<?php

namespace App\Console\Commands;

use App\Enums\CertificateStatus;
use App\Enums\IssuedCertificateStatus;
use App\Mail\CertificateIssuedMail;
use App\Models\Attendee;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\IssuedCertificate;
use App\Services\CertificateAudience;
use App\Services\CertificateEligibility;
use App\Services\DailyMailBudget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Throttled email delivery of Issued certificates: Released and Eligible Attendees with an email address and a
 * stored file, at most once each, within the shared daily cap and a per-run limit. Whatever is left goes out on
 * later runs, and Attendees who become Eligible later are picked up by the next run.
 */
#[Signature('app:send-certificate-emails')]
#[Description('Email Issued certificates to Eligible, Released Attendees under the shared daily mail cap')]
class SendCertificateEmails extends Command
{
    private int $sent = 0;

    private int $limit = 0;

    private int $cap = 0;

    public function handle(CertificateAudience $audience, CertificateEligibility $eligibility, DailyMailBudget $budget): int
    {
        $this->sent = 0;
        $this->cap = (int) config('certificates.email_daily_cap');
        $this->limit = (int) config('certificates.email_per_run');

        foreach ($this->certificates() as $certificate) {
            if ($this->sent >= $this->limit || ! $eligibility->isReleased($certificate)) {
                continue;
            }
            if (! $this->sendFor($certificate, $audience, $eligibility, $budget)) {
                break;
            }
        }

        $this->info("Emailed {$this->sent} issued certificates.");

        return self::SUCCESS;
    }

    /** @return Collection<int, Certificate> */
    private function certificates(): Collection
    {
        return Certificate::withoutGlobalScopes()
            ->where('status', CertificateStatus::Published)
            ->where('email_enabled', true)
            ->whereIn('event_id', Event::withoutGlobalScopes()->whereNull('archived_at')->select('id'))
            ->orderBy('id')
            ->get();
    }

    /** Returns false once the daily cap is used up, so the run stops. */
    private function sendFor(Certificate $certificate, CertificateAudience $audience, CertificateEligibility $eligibility, DailyMailBudget $budget): bool
    {
        $eventName = (string) Event::withoutGlobalScopes()->whereKey($certificate->event_id)->value('name');
        $capacityLeft = true;

        $this->pending($certificate, $audience)->chunkById(100, function (Collection $rows) use ($certificate, $eligibility, $budget, $eventName, &$capacityLeft) {
            $attendees = Attendee::query()->whereIn('id', $rows->pluck('attendee_id'))->get()->keyBy('id');
            $eligible = $eligibility->eligibleIds($certificate, $attendees);

            foreach ($rows->whereIn('attendee_id', $eligible) as $row) {
                if ($this->sent >= $this->limit) {
                    return false;
                }
                if (! $budget->reserve($this->cap)) {
                    $capacityLeft = false;

                    return false;
                }
                $this->deliver($row, $certificate, $attendees[$row->attendee_id], $eventName, $budget);
            }
        });

        return $capacityLeft;
    }

    /** Generated rows with a stored file, not yet emailed, of current Audience members who have an email address. */
    private function pending(Certificate $certificate, CertificateAudience $audience)
    {
        return IssuedCertificate::query()
            ->where('certificate_id', $certificate->id)
            ->where('status', IssuedCertificateStatus::Generated)
            ->whereNotNull('file_path')
            ->whereNull('file_deleted_at')
            ->whereNull('emailed_at')
            ->whereIn('attendee_id', $audience->query($certificate)
                ->whereNotNull('attendees.email_address')->where('attendees.email_address', '!=', '')
                ->select('attendees.id'))
            ->orderBy('id');
    }

    /** Claims the row first, so a concurrent run cannot send it too; a failed send gives the claim and the slot back. */
    private function deliver(IssuedCertificate $row, Certificate $certificate, Attendee $attendee, string $eventName, DailyMailBudget $budget): void
    {
        $claimed = IssuedCertificate::query()->whereKey($row->id)->whereNull('emailed_at')->update(['emailed_at' => now()]);
        if ($claimed !== 1) {
            $budget->release();

            return;
        }

        try {
            Mail::to($attendee->email_address)->send(new CertificateIssuedMail(
                Storage::disk('local')->get($row->file_path) ?? throw new \RuntimeException('The certificate file is missing.'),
                $certificate->title,
                $attendee->full_name,
                $eventName,
            ));
            $this->sent++;
        } catch (Throwable $e) {
            IssuedCertificate::query()->whereKey($row->id)->update(['emailed_at' => null]);
            $budget->release();
            Log::warning("Certificate email for issued certificate {$row->id} failed: ".$e->getMessage());
        }
    }
}
