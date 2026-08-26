<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportEventRegistrationsQrRequest;
use App\Http\Requests\StoreEventRegistrationRequest;
use App\Http\Resources\AttendeeResource;
use App\Http\Resources\EventRegistrationResource;
use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class EventRegistrationController extends Controller
{
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

        return EventRegistrationResource::collection($query->paginate());
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
            if (! empty($data['attendee_id'])) {
                $attendee = Attendee::findOrFail($data['attendee_id']);
            } else {
                if (! $override) {
                    $duplicates = Attendee::matchingName($data['first_name'], $data['middle_name'] ?? null, $data['last_name'])->get();

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
                    'created_by' => $request->user()->id,
                ]);
            }

            $registration = $event->registrations()->create([
                'attendee_id' => $attendee->id,
                'registered_by' => $request->user()->id,
            ]);

            AuditLog::record('event_registration.created', $registration, ['attendee_id' => $attendee->id]);

            GenerateAttendeeIdCardJob::dispatch($registration);

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
        $this->authorize('view', $registration);

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
        $this->authorize('update', $registration);

        GenerateAttendeeIdCardJob::dispatch($registration);

        return response()->json([
            'message' => 'Identification card generation has been queued.',
        ], 202);
    }
}
