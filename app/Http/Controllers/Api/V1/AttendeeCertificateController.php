<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendeeCertificateResource;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\IssuedCertificate;
use App\Services\CertificateAudience;
use App\Services\CertificateEligibility;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** The Attendee side of Certificates: own Issued certificates, Released and Eligible right now. */
class AttendeeCertificateController extends Controller
{
    public function index(Request $request, int $event, CertificateEligibility $eligibility, CertificateAudience $audience): AnonymousResourceCollection
    {
        return AttendeeCertificateResource::collection($this->available($request, $event, $eligibility, $audience)->values());
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
     * still in the Audience (a leaver's row is hidden), with a stored file. Only the caller's Linked Attendees
     * are ever consulted. One query loads every candidate row; the Audience and eligibility checks run once per Certificate.
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
        $issued = IssuedCertificate::query()->visible()
            ->whereIn('certificate_id', $certificates->modelKeys())
            ->whereIn('attendee_id', $attendees->modelKeys())
            ->get()->filter->hasFile()->groupBy('certificate_id');

        $rows = collect();
        foreach ($certificates as $certificate) {
            $mine = $issued->get($certificate->id, collect())->keyBy('attendee_id');
            if ($mine->isEmpty()) {
                continue;
            }

            // Audience membership stays a live check (one query per Certificate the caller holds a file of).
            $inAudience = $audience->query($certificate)->withoutGlobalScope('organization')
                ->whereIn('attendees.id', $mine->keys()->all())->pluck('attendees.id')->all();
            $eligibleId = $eligibility->eligibleIds($certificate, $attendees->whereIn('id', $inAudience))->first();
            if ($eligibleId !== null) {
                $rows->push($mine[$eligibleId]->setRelation('certificate', $certificate)->setRelation('attendee', $attendees->firstWhere('id', $eligibleId)));
            }
        }

        return $rows;
    }
}
