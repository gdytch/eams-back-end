<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendeeCertificateResource;
use App\Models\IssuedCertificate;
use App\Services\AttendeeCertificates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** The Attendee side of Certificates: own Issued certificates, Released and Eligible right now. */
class AttendeeCertificateController extends Controller
{
    public function index(Request $request, int $event, AttendeeCertificates $certificates): AnonymousResourceCollection
    {
        return AttendeeCertificateResource::collection($this->available($request, $event, $certificates)->values());
    }

    public function download(Request $request, int $event, int $certificate, AttendeeCertificates $certificates)
    {
        $issued = $this->available($request, $event, $certificates)
            ->first(fn (IssuedCertificate $row) => $row->certificate_id === $certificate);
        abort_unless($issued !== null && Storage::disk('local')->exists($issued->file_path), 404);

        $name = Str::slug("{$issued->certificate->title} {$issued->attendee->full_name}") ?: 'certificate';

        return Storage::disk('local')->download($issued->file_path, "{$name}.pdf", ['Content-Type' => 'application/pdf']);
    }

    /** @return Collection<int, IssuedCertificate> */
    private function available(Request $request, int $eventId, AttendeeCertificates $certificates): Collection
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        return $certificates->availableFor($user, $eventId);
    }
}
