<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\IssuedCertificate;
use App\Services\CertificateAudience;
use App\Services\CertificateEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** The Attendee side of Certificates: own Issued certificates, Released and Eligible right now. */
class AttendeeCertificateController extends Controller
{
    public function index(Request $request, int $event, CertificateEligibility $eligibility, CertificateAudience $audience): JsonResponse
    {
        $issued = $this->available($request, $event, $eligibility, $audience);

        return response()->json(['data' => $issued->map(fn (IssuedCertificate $row) => [
            'id' => $row->certificate->id,
            'title' => $row->certificate->title,
            'event_id' => $row->certificate->event_id,
            'released_at' => $row->certificate->release_at ?? $row->certificate->published_at,
            'download_url' => "/api/v1/attendee/events/{$row->certificate->event_id}/certificates/{$row->certificate->id}/download",
        ])->values()]);
    }

    public function download(Request $request, int $event, int $certificate, CertificateEligibility $eligibility, CertificateAudience $audience)
    {
        $issued = $this->available($request, $event, $eligibility, $audience)
            ->first(fn (IssuedCertificate $row) => $row->certificate_id === $certificate);
        abort_unless($issued !== null && Storage::disk('local')->exists($issued->file_path), 404);

        $name = Str::slug("{$issued->certificate->title} {$issued->attendee->full_name}") ?: 'certificate';

        return Storage::disk('local')->download($issued->file_path, "{$name}.pdf", ['Content-Type' => 'application/pdf']);
    }

    /**
     * The caller's own Issued certificates of one event they may see now: published, Released, Eligible,
     * still in the Audience, with a stored file. Only the caller's Linked Attendees are ever consulted.
     *
     * @return Collection<int, IssuedCertificate>
     */
    private function available(Request $request, int $eventId, CertificateEligibility $eligibility, CertificateAudience $audience): Collection
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $event = Event::withoutGlobalScopes()->findOrFail($eventId);
        $attendees = $user->attendees()->withoutGlobalScopes()->whereNull('merged_at')->where('event_id', $event->id)->get();
        if ($attendees->isEmpty()) {
            return collect();
        }

        $certificates = Certificate::withoutGlobalScopes()
            ->where('event_id', $event->id)->orderBy('id')->get()
            ->filter(fn (Certificate $certificate) => $eligibility->isReleased($certificate));

        $rows = collect();
        foreach ($certificates as $certificate) {
            $inAudience = $audience->query($certificate)->withoutGlobalScope('organization')
                ->whereIn('attendees.id', $attendees->modelKeys())->pluck('attendees.id');
            $candidates = $attendees->whereIn('id', $inAudience->all());

            foreach ($eligibility->eligibleIds($certificate, $candidates) as $attendeeId) {
                $issued = IssuedCertificate::query()
                    ->where('certificate_id', $certificate->id)->where('attendee_id', $attendeeId)->first();

                if ($issued?->hasFile()) {
                    $rows->push($issued->setRelation('certificate', $certificate)->setRelation('attendee', $attendees->firstWhere('id', $attendeeId)));
                    break;
                }
            }
        }

        return $rows;
    }
}
