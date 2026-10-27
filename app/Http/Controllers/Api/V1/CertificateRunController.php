<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\IssuedCertificateStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateAttendeeRowResource;
use App\Http\Resources\CertificateRunStatusResource;
use App\Http\Resources\IssuedCertificateResource;
use App\Jobs\GenerateCertificateJob;
use App\Jobs\GenerateIssuedCertificatesBatchJob;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\IssuedCertificate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

/** Admin management of a Certificate's generation run: status, retry, regenerate one file, zip download. */
class CertificateRunController extends Controller
{
    public function status(Event $event, Certificate $certificate)
    {
        $this->authorize('view', $certificate);

        $certificate->setRelation('issuedCertificates', $certificate->issuedCertificates()->visible()->with('attendee')->orderBy('id')->get());

        return CertificateRunStatusResource::make($certificate);
    }

    /** Paginated list of the visible Attendees with their file state, searchable by name or email. */
    public function attendees(Request $request, Event $event, Certificate $certificate)
    {
        $this->authorize('view', $certificate);

        $search = trim((string) $request->query('search', ''));
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $rows = $certificate->issuedCertificates()->visible()->with('attendee')
            ->when($search !== '', fn ($query) => $query->whereHas('attendee', fn ($attendee) => $attendee
                ->where(fn ($match) => $match
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email_address', 'like', "%{$search}%"))))
            ->orderBy('id')
            ->paginate($perPage);

        return CertificateAttendeeRowResource::collection($rows);
    }

    public function retryFailed(Event $event, Certificate $certificate)
    {
        $this->authorize('update', $certificate);
        $this->ensurePublished($certificate);

        $retried = $certificate->issuedCertificates()->visible()
            ->where('status', IssuedCertificateStatus::Failed)
            ->update(['status' => IssuedCertificateStatus::Pending, 'failure_reason' => null]);

        AuditLog::record('certificate.retried', $certificate, ['count' => $retried]);
        if ($retried > 0) {
            GenerateCertificateJob::dispatch($certificate->id, $certificate->generation_run);
        }

        return response()->json(['data' => ['retried' => $retried]]);
    }

    /** Bulk restore, e.g. after the retention purge and an unarchive: requeues every visible row without a usable file. */
    public function regenerateMissing(Event $event, Certificate $certificate)
    {
        $this->authorize('update', $certificate);
        $this->ensurePublished($certificate);

        $disk = Storage::disk('local');
        $ids = [];
        $certificate->issuedCertificates()->visible()
            ->whereIn('status', [IssuedCertificateStatus::Generated, IssuedCertificateStatus::Failed])
            ->chunkById(500, function ($rows) use ($disk, &$ids) {
                foreach ($rows as $row) {
                    if ($row->status === IssuedCertificateStatus::Failed || ! $row->hasFile() || ! $disk->exists($row->file_path)) {
                        $ids[] = $row->id;
                    }
                }
            });

        foreach (array_chunk($ids, 500) as $chunk) {
            IssuedCertificate::query()->whereIn('id', $chunk)
                ->update(['status' => IssuedCertificateStatus::Pending, 'failure_reason' => null]);
        }

        AuditLog::record('certificate.regenerated_missing', $certificate, ['count' => count($ids)]);
        foreach (array_chunk($ids, 25) as $chunk) {
            GenerateIssuedCertificatesBatchJob::dispatch($certificate->id, $certificate->generation_run, $chunk);
        }

        return response()->json(['data' => ['requeued' => count($ids)]]);
    }

    public function regenerate(Event $event, Certificate $certificate, int $attendee)
    {
        $this->authorize('update', $certificate);
        $this->ensurePublished($certificate);

        $issued = $certificate->issuedCertificates()->visible()->where('attendee_id', $attendee)->firstOrFail();
        $issued->update(['status' => IssuedCertificateStatus::Pending, 'failure_reason' => null]);

        AuditLog::record('certificate.regenerated', $certificate, ['attendee_id' => $attendee]);
        GenerateIssuedCertificatesBatchJob::dispatch($certificate->id, $certificate->generation_run, [$issued->id]);

        return IssuedCertificateResource::make($issued->refresh());
    }

    public function downloadAll(Event $event, Certificate $certificate)
    {
        $this->authorize('view', $certificate);

        $disk = Storage::disk('local');
        $files = $certificate->issuedCertificates()->visible()->with('attendee')->orderBy('id')->get()
            ->filter(fn (IssuedCertificate $row) => $row->hasFile() && $disk->exists($row->file_path));
        abort_if($files->isEmpty(), 404);

        $temporary = tempnam(sys_get_temp_dir(), 'certificates-');
        try {
            $archive = new ZipArchive;
            if ($archive->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the certificate archive.');
            }
            foreach ($files as $row) {
                $name = Str::slug($row->attendee?->full_name ?? '') ?: 'attendee';
                // Read from disk when the archive is closed, never held in memory.
                $archive->addFile($disk->path($row->file_path), "{$name}-{$row->attendee_id}.pdf");
            }
            if (! $archive->close()) {
                throw new RuntimeException('Unable to write the certificate archive.');
            }
        } catch (\Throwable $e) {
            @unlink($temporary);
            throw $e;
        }

        $title = Str::slug($certificate->title) ?: 'certificates';

        return response()->download($temporary, "{$title}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    private function ensurePublished(Certificate $certificate): void
    {
        if (! $certificate->isPublished()) {
            throw ValidationException::withMessages(['status' => 'Publish the certificate first.']);
        }
    }
}
