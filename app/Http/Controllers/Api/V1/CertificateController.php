<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CertificateFont;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCertificateRequest;
use App\Http\Requests\SetCertificateGoogleFontRequest;
use App\Http\Requests\UploadCertificateTemplateRequest;
use App\Http\Resources\CertificateResource;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Event;
use App\Services\GoogleFontException;
use App\Services\GoogleFontService;
use Illuminate\Support\Facades\Storage;
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
            ->create([...$request->validated(), 'organization_id' => $event->organization_id])
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
        $certificate->update($request->validated());
        AuditLog::record('certificate.updated', $certificate);

        return CertificateResource::make($certificate);
    }

    public function destroy(Event $event, Certificate $certificate)
    {
        $this->authorize('delete', $certificate);

        if ($certificate->template_path) {
            Storage::disk('public')->delete($certificate->template_path);
        }
        $certificate->delete();
        AuditLog::record('certificate.deleted', $certificate);

        return response()->noContent();
    }

    public function setGoogleFont(SetCertificateGoogleFontRequest $request, Event $event, Certificate $certificate, GoogleFontService $fonts)
    {
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
        $previous = $certificate->template_path;
        $path = $request->file('template')->store("events/{$event->id}/certificates", 'public');
        $certificate->update(['template_path' => $path]);

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }
        AuditLog::record('certificate.template_updated', $certificate);

        return CertificateResource::make($certificate);
    }
}
