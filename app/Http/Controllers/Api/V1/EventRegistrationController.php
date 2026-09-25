<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportEventRegistrationsQrRequest;
use App\Http\Requests\StartIdCardGridDownloadRequest;
use App\Http\Requests\StoreEventRegistrationRequest;
use App\Http\Resources\AttendeeResource;
use App\Http\Resources\EventRegistrationResource;
use App\Http\Resources\IdCardGridDownloadResource;
use App\Jobs\GenerateAttendeeIdCardJob;
use App\Jobs\PrepareIdCardGridDownloadJob;
use App\Mail\EventRegistrationWelcomeMail;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\IdCardGridDownload;
use App\Services\AttendeeInvitationService;
use App\Services\PdfMergeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class EventRegistrationController extends Controller
{
    public function __construct(private AttendeeInvitationService $invitationService) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Event $event)
    {
        $this->authorize('view', $event);

        $query = $event->registrations()->with(['attendee.union', 'attendee.mission']);

        if ($search = trim((string) $request->string('search'))) {
            $query->whereHas('attendee', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        // Apply sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'last_name') {
            $query->join('attendees', 'event_registrations.attendee_id', '=', 'attendees.id')
                ->orderBy('attendees.last_name', $sortOrder)
                ->select('event_registrations.*');
        } elseif ($sortBy === 'organization_level') {
            $query->join('attendees', 'event_registrations.attendee_id', '=', 'attendees.id')
                ->orderBy('attendees.union_id', $sortOrder)
                ->orderBy('attendees.mission_id', $sortOrder)
                ->select('event_registrations.*');
        } elseif ($sortBy === 'registered_at') {
            $query->orderBy('registered_at', $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        return EventRegistrationResource::collection($query->paginate($request->input('per_page', 10)));
    }

    /**
     * Store a newly created resource in storage: registers an existing attendee,
     * or creates a new attendee and registers them, in a single call.
     */
    public function store(StoreEventRegistrationRequest $request, Event $event)
    {
        $data = $request->validated();
        $override = (bool) ($data['override_duplicate'] ?? false);

        return DB::transaction(function () use ($data, $override, $event, $request) {
            $attendeeWasJustCreated = false;

            if (! empty($data['attendee_id'])) {
                $attendee = Attendee::findOrFail($data['attendee_id']);
            } else {
                if (! $override) {
                    $duplicates = Attendee::matchingName($data['first_name'], $data['last_name']);

                    if ($duplicates->isNotEmpty()) {
                        return response()->json([
                            'message' => 'Potential duplicate attendees found. Pass override_duplicate=true to create anyway.',
                            'duplicates' => AttendeeResource::collection($duplicates),
                        ], 409);
                    }
                }

                $attendee = Attendee::create([
                    'organization_id' => $event->organization_id,
                    'union_id' => $data['union_id'] ?? null,
                    'mission_id' => $data['mission_id'] ?? null,
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => $data['last_name'],
                    'email_address' => $data['email_address'] ?? null,
                    'created_by' => $request->user()->id,
                ]);

                $attendeeWasJustCreated = true;
            }

            $registration = $event->registrations()->create([
                'attendee_id' => $attendee->id,
                'registered_by' => $request->user()->id,
            ]);

            AuditLog::record('event_registration.created', $registration, ['attendee_id' => $attendee->id]);

            GenerateAttendeeIdCardJob::dispatch($registration);

            // Send welcome email if attendee has an email
            // Temporarily disabled sending welcome email to because of email sending rate limits. Can be re-enabled later if needed.
            // $email = $attendee->email_address ?? $attendee->user?->email;
            // if ($email) {
            //     Mail::to($email)->send(new EventRegistrationWelcomeMail($registration));
            // }

            // Send account invitation if attendee was just created
            if ($attendeeWasJustCreated) {
                $this->invitationService->sendIfEligible($attendee, $request->user());
            }

            return EventRegistrationResource::make($registration->load('attendee'))->response()->setStatusCode(201);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(Event $event, EventRegistration $registration)
    {
        $this->authorize('view', $registration);

        return EventRegistrationResource::make($registration->load('attendee'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Event $event, EventRegistration $registration)
    {
        $this->authorize('delete', $registration);

        AuditLog::record('event_registration.deleted', $registration, ['attendee_id' => $registration->attendee_id]);

        $registration->delete();

        return response()->noContent();
    }

    /**
     * Generate a print-ready PDF of QR codes for all or selected attendees registered to the event.
     */
    public function exportQr(ExportEventRegistrationsQrRequest $request, Event $event)
    {
        $this->authorize('manageIdCards', $event);

        $query = $event->registrations()->with('attendee');

        if ($request->filled('registration_ids')) {
            $query->whereIn('id', $request->validated('registration_ids'));
        }

        $registrations = $query->get();

        // dompdf cannot render inline <svg> elements, so embed the QR as a base64 data URI <img> instead.
        $qrImages = $registrations->mapWithKeys(fn (EventRegistration $registration) => [
            $registration->id => 'data:image/svg+xml;base64,'.base64_encode(
                QrCode::format('svg')->size(160)->margin(0)->generate($registration->qr_token)
            ),
        ]);

        $pdf = Pdf::loadView('pdf.bulk-qr-codes', [
            'event' => $event,
            'registrations' => $registrations,
            'qrImages' => $qrImages,
        ]);

        AuditLog::record('event_registration.qr_pdf_exported', $event, [
            'registration_ids' => $registrations->pluck('id')->all(),
        ]);

        return $pdf->download("event-{$event->id}-qr-codes.pdf");
    }

    /**
     * Download the attendee's generated identification card PDF.
     */
    public function downloadIdCard(Event $event, EventRegistration $registration)
    {
        $this->authorize('downloadIdCard', $registration);

        if ($registration->id_card_path === null || ! Storage::disk('local')->exists($registration->id_card_path)) {
            return response()->json([
                'message' => 'The identification card is still being generated. Try again shortly.',
            ], 202);
        }

        return Storage::disk('local')->download(
            $registration->id_card_path,
            "{$registration->attendee->first_name}-{$registration->attendee->last_name}-id-card.pdf"
        );
    }

    /**
     * Re-queue identification card generation (e.g. after the organization's background image changes).
     */
    public function regenerateIdCard(Event $event, EventRegistration $registration)
    {
        $this->authorize('manageIdCards', $registration);

        GenerateAttendeeIdCardJob::dispatch($registration);

        return response()->json([
            'message' => 'Identification card generation has been queued.',
        ], 202);
    }

    /**
     * Bulk download identification cards for specified registration IDs.
     * Merges all requested ID card PDFs into a single document.
     */
    public function bulkDownloadIdCards(Request $request, Event $event)
    {
        $this->authorize('manageIdCards', $event);

        $registrationIds = $request->input('registration_ids', []);

        if (empty($registrationIds)) {
            return response()->json([
                'message' => 'At least one registration ID is required.',
            ], 400);
        }

        $registrations = $event->registrations()
            ->whereIn('id', $registrationIds)
            ->with('attendee')
            ->get();

        if ($registrations->isEmpty()) {
            return response()->json([
                'message' => 'No registrations found with the provided IDs.',
            ], 404);
        }

        // Collect valid ID card paths (filter out missing or not yet generated cards)
        $pdfPaths = $registrations
            ->filter(
                fn (EventRegistration $reg) => $reg->id_card_path !== null &&
                    Storage::disk('local')->exists($reg->id_card_path)
            )
            ->pluck('id_card_path')
            ->all();

        if (empty($pdfPaths)) {
            return response()->json([
                'message' => 'None of the requested ID cards are ready for download. Please try again shortly.',
            ], 202);
        }

        $mergeService = new PdfMergeService;
        $mergedPdf = $mergeService->merge($pdfPaths, 'local');

        AuditLog::record('event_registration.bulk_id_cards_downloaded', $event, [
            'registration_ids' => $registrations->pluck('id')->all(),
            'count' => count($pdfPaths),
        ]);

        return response($mergedPdf)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"event-{$event->id}-id-cards.pdf\"");
    }

    /**
     * Download all identification cards for all registered attendees in the event.
     * Merges all ID card PDFs into a single document.
     */
    public function downloadAllIdCards(Event $event)
    {
        $this->authorize('manageIdCards', $event);

        $registrations = $event->registrations()
            ->with('attendee')
            ->get();

        if ($registrations->isEmpty()) {
            return response()->json([
                'message' => 'No registrations found for this event.',
            ], 404);
        }

        // Collect valid ID card paths (filter out missing or not yet generated cards)
        $pdfPaths = $registrations
            ->filter(
                fn (EventRegistration $reg) => $reg->id_card_path !== null &&
                    Storage::disk('local')->exists($reg->id_card_path)
            )
            ->pluck('id_card_path')
            ->all();

        if (empty($pdfPaths)) {
            return response()->json([
                'message' => 'ID cards are still being generated. Try again shortly.',
            ], 202);
        }

        $mergeService = new PdfMergeService;
        $mergedPdf = $mergeService->merge($pdfPaths, 'local');

        AuditLog::record('event_registration.all_id_cards_downloaded', $event, [
            'count' => count($pdfPaths),
        ]);

        return response($mergedPdf)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"event-{$event->id}-all-id-cards.pdf\"");
    }

    /**
     * Stream the generated identification card image for display on the frontend.
     */
    public function getIdCardImage(Event $event, EventRegistration $registration)
    {
        $this->authorize('viewIdCardImage', $registration);

        // Validate that id_card_images_path is a non-empty array
        $imagePaths = $registration->id_card_images_path;

        if (! is_array($imagePaths) || empty($imagePaths)) {
            return response()->json([
                'message' => 'The identification card image is still being generated. Try again shortly.',
            ], 202);
        }

        $imagePath = $imagePaths[0] ?? null;

        if (! $imagePath || ! is_string($imagePath)) {
            return response()->json([
                'message' => 'No valid image path found in registration.',
            ], 404);
        }

        // Normalize the path (remove leading slash if present)
        $imagePath = ltrim($imagePath, '/');

        if (! Storage::disk('local')->exists($imagePath)) {
            return response()->json([
                'message' => 'Identification card image not found.',
                'debug' => [
                    'requested_path' => $imagePath,
                    'full_path' => Storage::disk('local')->path($imagePath),
                ],
            ], 404);
        }

        return response()->file(Storage::disk('local')->path($imagePath));
    }

    /**
     * Start a background job to generate batch PDFs of ID card images for specified registrations.
     * Returns a download batch ID; use GET /id-cards/grid-download/{id} to poll status,
     * then GET /id-cards/grid-download/{id}/file to download once completed.
     */
    public function startIdCardGridDownload(StartIdCardGridDownloadRequest $request, Event $event)
    {
        $this->authorize('manageIdCards', $event);

        $data = $request->validated();
        $registrationIds = $data['registration_ids'] ?? null;

        // If specific registration IDs provided, verify they exist and belong to this event
        if ($registrationIds !== null) {
            $registrations = $event->registrations()
                ->whereIn('id', $registrationIds)
                ->get();

            if ($registrations->isEmpty()) {
                return response()->json([
                    'message' => 'No registrations found with the provided IDs.',
                ], 404);
            }
        }

        $download = IdCardGridDownload::create([
            'event_id' => $event->id,
            'requested_by' => $request->user()->id,
            'registration_ids' => $registrationIds,
            'status' => 'pending',
        ]);

        $download->refresh();

        PrepareIdCardGridDownloadJob::dispatch($download->id);

        AuditLog::record('event_registration.id_card_grid_download_started_v2', $event, [
            'download_id' => $download->id,
            'registration_ids' => $registrationIds,
        ]);

        return IdCardGridDownloadResource::make($download)->response()->setStatusCode(202);
    }

    /**
     * Start a background job to generate batch PDFs of ID card images for all registrations.
     * Returns a download batch ID; use GET /id-cards/grid-download/{id} to poll status,
     * then GET /id-cards/grid-download/{id}/file to download once completed.
     */
    public function startIdCardGridDownloadAll(Event $event)
    {
        $this->authorize('manageIdCards', $event);

        if ($event->registrations()->count() === 0) {
            return response()->json([
                'message' => 'No registrations found for this event.',
            ], 404);
        }

        $download = IdCardGridDownload::create([
            'event_id' => $event->id,
            'requested_by' => auth()->user()->id,
            'registration_ids' => null,
            'status' => 'pending',
        ]);

        $download->refresh();

        PrepareIdCardGridDownloadJob::dispatch($download->id);

        AuditLog::record('event_registration.id_card_grid_download_started_v2', $event, [
            'download_id' => $download->id,
            'registration_ids' => null,
        ]);

        return IdCardGridDownloadResource::make($download)->response()->setStatusCode(202);
    }

    /**
     * Check the status of a grid ZIP download batch.
     */
    public function showIdCardGridDownload(Event $event, IdCardGridDownload $gridDownload)
    {
        $this->authorize('manageIdCards', $event);

        if ($gridDownload->event_id !== $event->id) {
            return response()->json([
                'message' => 'Download not found.',
            ], 404);
        }

        return IdCardGridDownloadResource::make($gridDownload);
    }

    /**
     * Download the completed ZIP containing the grid PDF batches.
     * Returns 202 if still processing, 422 if generation failed.
     */
    public function downloadIdCardGridDownload(Event $event, IdCardGridDownload $gridDownload)
    {
        $this->authorize('manageIdCards', $event);

        if ($gridDownload->event_id !== $event->id) {
            return response()->json([
                'message' => 'Download not found.',
            ], 404);
        }

        if ($gridDownload->status->value === 'pending' || $gridDownload->status->value === 'processing') {
            return response()->json([
                'message' => 'Grid ZIP is still being generated. Check back shortly.',
                'status' => $gridDownload->status->value,
            ], 202);
        }

        if ($gridDownload->status->value === 'failed') {
            return response()->json([
                'message' => $gridDownload->failure_reason,
            ], 422);
        }

        $disk = Storage::disk('local');
        $filePath = $gridDownload->file_path;

        if ($filePath === null || ! $disk->exists($filePath)) {
            return response()->json([
                'message' => 'This ZIP download is no longer available.',
            ], 404);
        }

        return response()->streamDownload(
            function () use ($disk, $filePath): void {
                $stream = $disk->readStream($filePath);

                if ($stream === false) {
                    throw new \RuntimeException('Unable to read ZIP archive.');
                }

                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                    $disk->delete($filePath);
                }
            },
            "event-{$event->id}-id-card-grid-batches.zip",
            [
                'Content-Type' => 'application/zip',
                'Content-Length' => $disk->size($filePath),
            ]
        );
    }
}
