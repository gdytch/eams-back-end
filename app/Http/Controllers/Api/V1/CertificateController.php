<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CertificateFont;
use App\Enums\CertificateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCertificateRequest;
use App\Http\Requests\SetCertificateGoogleFontRequest;
use App\Http\Requests\UploadCertificateTemplateRequest;
use App\Http\Resources\CertificateAudienceCountResource;
use App\Http\Resources\CertificateResource;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Event;
use App\Services\CertificateAudience;
use App\Services\CertificateAudienceSync;
use App\Services\GoogleFontException;
use App\Services\GoogleFontService;
use App\Services\PublishCertificate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CertificateController extends Controller
{
    public function index(Event $event)
    {
        $this->authorize('viewAny', [Certificate::class, $event]);

        $certificates = $event->certificates()->orderBy('id')->get();
        Certificate::generationCountsFor($certificates);

        return CertificateResource::collection($certificates);
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

    public function update(SaveCertificateRequest $request, Event $event, Certificate $certificate, CertificateAudienceSync $sync)
    {
        $certificate->fill($request->certificateAttributes());
        $audienceChanged = $certificate->isDirty(['audience_union_ids', 'audience_mission_ids', 'audience_attendee_ids', 'audience_breakout_ids']);
        $certificate->save();
        AuditLog::record('certificate.updated', $certificate);

        if ($audienceChanged && $certificate->isPublished()) {
            $sync->reconcile($certificate);
        }

        return CertificateResource::make($certificate);
    }

    public function audienceCount(Event $event, Certificate $certificate, CertificateAudience $audience)
    {
        $this->authorize('view', $certificate);

        return CertificateAudienceCountResource::make($audience->count($certificate));
    }

    public function destroy(Event $event, Certificate $certificate)
    {
        $this->authorize('delete', $certificate);

        if ($certificate->isPublished()) {
            throw ValidationException::withMessages(['status' => 'Unpublish the certificate before deleting it.']);
        }

        Storage::disk('public')->delete(array_filter([$certificate->template_path]));
        Storage::disk('local')->delete(array_filter([$certificate->template_pdf_path]));
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
        $previousPdf = $certificate->template_pdf_path;
        $pdfPath = $request->file('template_pdf')?->store("events/{$event->id}/certificates", 'local');
        $certificate->update(['template_path' => $path, 'template_pdf_path' => $pdfPath]);

        Storage::disk('public')->delete(array_filter([$previous]));
        Storage::disk('local')->delete(array_filter([$previousPdf]));
        AuditLog::record('certificate.template_updated', $certificate);

        return CertificateResource::make($certificate);
    }

    public function publish(Event $event, Certificate $certificate, PublishCertificate $publish)
    {
        $this->authorize('update', $certificate);

        return CertificateResource::make($publish($certificate));
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

        $issued = $certificate->issuedCertificates()->visible()->where('attendee_id', $attendee)->with('attendee')->first();
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
