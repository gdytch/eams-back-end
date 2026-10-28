<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\IssuedCertificateStatus;
use App\Jobs\GenerateIssuedCertificatesBatchJob;
use App\Models\Attendee;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\IssuedCertificate;
use Illuminate\Validation\ValidationException;

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
        // One cheap query ends the common case: an event with no published Certificate.
        $certificates = Certificate::withoutGlobalScopes()
            ->where('event_id', $attendee->event_id)
            ->where('status', CertificateStatus::Published)
            ->get();
        if ($certificates->isEmpty()) {
            return;
        }

        $event = Event::withoutGlobalScopes()->find($attendee->event_id);
        if ($event === null || $event->isArchived()) {
            return;
        }

        $certificates->each(fn (Certificate $certificate) => $this->syncCertificate($certificate, $attendee, $nameChanged));
    }

    /**
     * After an admin edit of a published Certificate's Audience: creates the missing rows of newly covered
     * Attendees (queued for generation), un-hides returners and hides the rows of Attendees who left.
     */
    public function reconcile(Certificate $certificate): void
    {
        $this->hideLeavers($certificate);
        $this->unhideReturners($certificate);
        $this->createMissingRows($certificate);
        $this->dispatchPending($certificate);
    }

    /**
     * Certificates of the event whose Audience lists the Breakout.
     *
     * @return \Illuminate\Support\Collection<int, Certificate>
     */
    private function usingBreakout(int $eventId, int $breakoutId)
    {
        return Certificate::withoutGlobalScopes()->where('event_id', $eventId)->lockForUpdate()->get()
            ->filter(fn (Certificate $c) => in_array($breakoutId, $c->audience_breakout_ids ?? [], true));
    }

    /** Refuses when dropping the Breakout would leave a published Certificate with an empty Audience (empty means everyone). */
    public function guardBreakoutRemoval(int $eventId, int $breakoutId): void
    {
        $emptied = $this->usingBreakout($eventId, $breakoutId)->first(fn (Certificate $c) => $c->isPublished()
            && ! ($c->audience_union_ids || $c->audience_mission_ids || $c->audience_attendee_ids)
            && array_values(array_diff($c->audience_breakout_ids, [$breakoutId])) === []);
        if ($emptied !== null) {
            throw ValidationException::withMessages(['breakout' => "This breakout is the only audience of the published certificate \"{$emptied->title}\". Unpublish it or change its audience first."]);
        }
    }

    /**
     * Removes the Breakout from every Audience listing it. Returns the published Certificates to reconcile once the
     * Breakout is gone.
     *
     * @return \Illuminate\Support\Collection<int, Certificate>
     */
    public function removeBreakout(int $eventId, int $breakoutId)
    {
        return $this->usingBreakout($eventId, $breakoutId)->each(function (Certificate $c) use ($breakoutId) {
            $c->audience_breakout_ids = array_values(array_diff($c->audience_breakout_ids, [$breakoutId]));
            $c->save();
        })->filter(fn (Certificate $c) => $c->isPublished())->values();
    }

    private function unhideReturners(Certificate $certificate): void
    {
        $inAudience = $this->audience->query($certificate)->select('attendees.id');
        IssuedCertificate::query()->where('certificate_id', $certificate->id)
            ->whereNotNull('hidden_at')->whereIn('attendee_id', $inAudience)
            ->update(['hidden_at' => null]);
    }

    private function createMissingRows(Certificate $certificate): void
    {
        $existing = IssuedCertificate::query()->where('certificate_id', $certificate->id)->select('attendee_id');
        $this->audience->query($certificate)->select('attendees.id')->whereNotIn('attendees.id', $existing)
            ->orderBy('attendees.id')->chunkById(500, function ($attendees) use ($certificate) {
                IssuedCertificate::insert($attendees->map(fn ($attendee) => [
                    'certificate_id' => $certificate->id, 'attendee_id' => $attendee->id,
                    'status' => IssuedCertificateStatus::Pending->value, 'name_too_long' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ])->all());
            }, 'attendees.id', 'id');
    }

    private function dispatchPending(Certificate $certificate): void
    {
        IssuedCertificate::query()->where('certificate_id', $certificate->id)->visible()
            ->where('status', IssuedCertificateStatus::Pending)->orderBy('id')->pluck('id')->chunk(25)
            ->each(fn ($ids) => GenerateIssuedCertificatesBatchJob::dispatch($certificate->id, $certificate->generation_run, $ids->values()->all())->afterCommit());
    }

    /** Hides, keeping the file, every visible row whose Attendee is no longer in the Audience. */
    public function hideLeavers(Certificate $certificate): void
    {
        IssuedCertificate::query()->where('certificate_id', $certificate->id)->whereNull('hidden_at')
            ->whereNotIn('attendee_id', $this->audience->query($certificate)->select('attendees.id'))
            ->update(['hidden_at' => now()]);
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
