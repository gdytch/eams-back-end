<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CertificateFont;
use App\Enums\CertificateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCertificateRequest;
use App\Http\Requests\SetCertificateGoogleFontRequest;
use App\Http\Requests\UploadCertificateTemplateRequest;
use App\Http\Resources\CertificateResource;
use App\Jobs\GenerateCertificateJob;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Event;
use App\Models\IssuedCertificate;
use App\Services\CertificateAudience;
use App\Services\GoogleFontException;
use App\Services\GoogleFontService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CertificateController extends Controller
{
    public function index(Event $event)
    {
        $this->authorize('viewAny', [Certificate::class, $event]);

        return CertificateResource::collection($event->certificates()->orderBy('id')->get());
    }

    public function store(SaveCertificateRequest $request, Event $event)
    {
        $certificate = $event->certificates()
            ->create([...$request->certificateAttributes(), 'organization_id' => $event->organization_id])
            ->refresh();
        AuditLog::record('certificate.created', $certificate);

        return CertificateResource::make($certificate)->response()->setStatusCode(201);
    }

    public function show(Event $event, Certificate $certificate)
    {
        $this->authorize('view', $certificate);

        return CertificateResource::make($certificate);
    }

    public function update(SaveCertificateRequest $request, Event $event, Certificate $certificate)
    {
        $certificate->update($request->certificateAttributes());
        AuditLog::record('certificate.updated', $certificate);

        return CertificateResource::make($certificate);
    }

    public function audienceCount(Event $event, Certificate $certificate, CertificateAudience $audience)
    {
        $this->authorize('view', $certificate);

        return response()->json(['data' => ['count' => $audience->count($certificate)]]);
    }

    public function destroy(Event $event, Certificate $certificate)
    {
        $this->authorize('delete', $certificate);

        if ($certificate->template_path) {
            Storage::disk('public')->delete($certificate->template_path);
        }
        Storage::disk('local')->deleteDirectory("events/{$event->id}/certificates/{$certificate->id}");
        $certificate->delete();
        AuditLog::record('certificate.deleted', $certificate);

        return response()->noContent();
    }

    public function setGoogleFont(SetCertificateGoogleFontRequest $request, Event $event, Certificate $certificate, GoogleFontService $fonts)
    {
        $this->ensureDesignUnlocked($certificate, 'url');
        $family = $request->family();

        try {
            $path = $fonts->cachedFont($family);
        } catch (GoogleFontException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $certificate->update([
            'font' => CertificateFont::Google,
            'google_font_family' => $family,
            'google_font_path' => $path,
        ]);
        AuditLog::record('certificate.google_font_set', $certificate);

        return CertificateResource::make($certificate);
    }

    public function uploadTemplate(UploadCertificateTemplateRequest $request, Event $event, Certificate $certificate)
    {
        $this->ensureDesignUnlocked($certificate, 'template');
        $previous = $certificate->template_path;
        $path = $request->file('template')->store("events/{$event->id}/certificates", 'public');
        $certificate->update(['template_path' => $path]);

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }
        AuditLog::record('certificate.template_updated', $certificate);

        return CertificateResource::make($certificate);
    }

    public function publish(Event $event, Certificate $certificate, CertificateAudience $audience)
    {
        $this->authorize('update', $certificate);

        if ($certificate->isPublished()) {
            throw ValidationException::withMessages(['status' => 'This certificate is already published.']);
        }
        if (! $certificate->template_path) {
            throw ValidationException::withMessages(['template' => 'Upload a Template before publishing.']);
        }

        $count = DB::transaction(function () use ($certificate, $audience) {
            $certificate->update([
                'status' => CertificateStatus::Published,
                'published_at' => now(),
                'generation_run' => $certificate->generation_run + 1,
            ]);

            $count = 0;
            $audience->query($certificate)->select('attendees.id')->orderBy('attendees.id')->chunkById(500, function ($attendees) use ($certificate, &$count) {
                $count += $attendees->count();
                IssuedCertificate::upsert(
                    $attendees->map(fn ($attendee) => [
                        'certificate_id' => $certificate->id, 'attendee_id' => $attendee->id,
                        'status' => 'pending', 'failure_reason' => null, 'name_too_long' => false,
                        'created_at' => now(), 'updated_at' => now(),
                    ])->all(),
                    ['certificate_id', 'attendee_id'],
                    ['status', 'failure_reason', 'name_too_long', 'updated_at'],
                );
            }, 'attendees.id', 'id');

            return $count;
        });

        AuditLog::record('certificate.published', $certificate, ['audience' => $count]);
        GenerateCertificateJob::dispatch($certificate->id, $certificate->generation_run);

        return CertificateResource::make($certificate->refresh());
    }

    public function unpublish(Event $event, Certificate $certificate)
    {
        $this->authorize('update', $certificate);

        $certificate->update(['status' => CertificateStatus::Draft, 'published_at' => null]);
        AuditLog::record('certificate.unpublished', $certificate);

        return CertificateResource::make($certificate);
    }

    public function download(Event $event, Certificate $certificate, int $attendee)
    {
        $this->authorize('view', $certificate);

        $issued = $certificate->issuedCertificates()->where('attendee_id', $attendee)->with('attendee')->first();
        abort_if($issued?->file_deleted_at !== null, 410, 'The file of this certificate was deleted after the event was archived. Regenerate it.');
        abort_unless($issued?->hasFile() && Storage::disk('local')->exists($issued->file_path), 404);

        $name = Str::slug("{$certificate->title} {$issued->attendee->full_name}") ?: 'certificate';

        return Storage::disk('local')->download($issued->file_path, "{$name}.pdf", ['Content-Type' => 'application/pdf']);
    }

    /** The design is what every stored file was rendered from, so it cannot change while published. */
    private function ensureDesignUnlocked(Certificate $certificate, string $field): void
    {
        if ($certificate->isPublished()) {
            throw ValidationException::withMessages([$field => 'The design is locked while the certificate is published. Unpublish it first.']);
        }
    }
}
