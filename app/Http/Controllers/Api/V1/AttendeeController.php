<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\AttendeeExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckAttendeeDuplicatesRequest;
use App\Http\Requests\StoreAttendeeRequest;
use App\Http\Requests\UpdateAttendeeRequest;
use App\Http\Requests\UploadAttendeePhotoRequest;
use App\Http\Resources\AttendeeResource;
use App\Http\Resources\EventRegistrationResource;
use App\Jobs\GenerateAttendeeIdCardJob;
use App\Mail\EventRegistrationWelcomeMail;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\AttendeeInvitationService;
use App\Services\ImageUploadService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class AttendeeController extends Controller
{
    public function __construct(private AttendeeInvitationService $invitationService) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Attendee::class);

        $query = Attendee::query();

        if ($search = trim((string) $request->string('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        if ($eventId = $request->input('event_id')) {
            $query->whereHas('registrations', function ($q) use ($eventId) {
                $q->where('event_id', $eventId);
            });
        }

        $query->with(['union', 'mission', 'church']);

        // Apply sorting
        $sortBy = $request->input('sort_by', 'last_name');
        $sortOrder = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';

        if (in_array($sortBy, ['last_name', 'organization_level'], true)) {

            if ($sortBy === 'organization_level') {
                $query->orderByRaw("CASE organization_level
                    WHEN 'union' THEN 1
                    WHEN 'mission' THEN 2
                    ELSE 3 END {$sortOrder}");
            } else {
                $query->orderBy($sortBy, $sortOrder);
            }
        } else {
            $query->orderBy('last_name', $sortOrder);
        }

        return AttendeeResource::collection($query->paginate($request->input('per_page', 10)));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAttendeeRequest $request)
    {
        $data = $request->validated();
        $override = (bool) ($data['override_duplicate'] ?? false);
        $autoRegister = (bool) ($data['auto_register'] ?? false);
        $eventId = $data['event_id'] ?? null;
        unset($data['override_duplicate'], $data['auto_register'], $data['event_id']);

        if (! $override) {
            $duplicates = Attendee::matchingName($data['first_name'], $data['middle_name'] ?? null, $data['last_name'])->get();

            if ($duplicates->isNotEmpty()) {
                return response()->json([
                    'message' => 'Potential duplicate attendees found. Pass override_duplicate=true to create anyway.',
                    'duplicates' => AttendeeResource::collection($duplicates),
                ], 409);
            }
        }

        $data['created_by'] = $request->user()->id;

        return DB::transaction(function () use ($data, $request, $autoRegister, $eventId) {
            $attendee = Attendee::create($data);

            AuditLog::record('attendee.created', $attendee);

            $this->invitationService->sendIfEligible($attendee, $request->user());

            $registration = null;

            if ($autoRegister && $eventId) {
                $event = Event::findOrFail($eventId);

                $registration = $event->registrations()->create([
                    'attendee_id' => $attendee->id,
                    'registered_by' => $request->user()->id,
                ]);

                AuditLog::record('event_registration.created', $registration, ['attendee_id' => $attendee->id]);

                GenerateAttendeeIdCardJob::dispatch($registration);

                if ($attendee->email_address) {
                    Mail::to($attendee->email_address)->send(new EventRegistrationWelcomeMail($registration));
                }
            }

            return AttendeeResource::make($attendee)
                ->additional(['registration' => $registration ? EventRegistrationResource::make($registration) : null])
                ->response()->setStatusCode(201);
        });
    }

    /**
     * Check for potential duplicate attendees by name, without creating one.
     */
    public function checkDuplicates(CheckAttendeeDuplicatesRequest $request)
    {
        $duplicates = Attendee::matchingName(
            $request->validated('first_name'),
            $request->validated('middle_name'),
            $request->validated('last_name'),
        )->get();

        return AttendeeResource::collection($duplicates);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Attendee $attendee)
    {
        $this->authorize('view', $attendee);
        $attendee->load('union', 'mission', 'church');
        $eventStats = null;
        $eventId = $request->input('event_id');

        if ($eventId !== null) {
            $registration = EventRegistration::where('attendee_id', $attendee->id)
                ->where('event_id', $eventId)
                ->with(['attendanceRecords', 'event.sessions'])
                ->first();

            if ($registration !== null) {
                $checkedInSessionIds = $registration->attendanceRecords
                    ->filter(fn($record) => $record->check_in_at !== null)
                    ->pluck('event_session_id')
                    ->unique()
                    ->values()
                    ->all();

                $absentCount = 0;
                foreach ($registration->event->sessions as $session) {
                    if ($session->endsAt()->isPast() && ! in_array($session->id, $checkedInSessionIds, true)) {
                        $absentCount++;
                    }
                }

                $eventStats = [
                    'registered' => true,
                    'registered_at' => $registration->registered_at,
                    'present_count' => count($checkedInSessionIds),
                    'absent_count' => $absentCount,
                    'total_sessions' => $registration->event->sessions->count(),
                    'registration_count' => $attendee->registrations()->count(),
                    'qr_token' => $registration->qr_token,
                ];
            }
        }

        return AttendeeResource::make($attendee)->additional(['event_stats' => $eventStats]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAttendeeRequest $request, Attendee $attendee)
    {
        $data = $request->validated();

        return DB::transaction(function () use ($attendee, $data) {
            $attendee->update($data);

            // Sync name fields to linked user
            if ($attendee->user_id !== null) {
                $userUpdate = [];
                if (isset($data['first_name'])) {
                    $userUpdate['first_name'] = $data['first_name'];
                }
                if (isset($data['middle_name'])) {
                    $userUpdate['middle_name'] = $data['middle_name'];
                }
                if (isset($data['last_name'])) {
                    $userUpdate['last_name'] = $data['last_name'];
                }

                // Reconstruct full name from parts if any were updated
                if ($userUpdate) {
                    $firstName = $userUpdate['first_name'] ?? $attendee->user->first_name ?? '';
                    $middleName = isset($userUpdate['middle_name']) ? $userUpdate['middle_name'] : $attendee->user->middle_name;
                    $lastName = isset($userUpdate['last_name']) ? $userUpdate['last_name'] : $attendee->user->last_name ?? '';
                    $userUpdate['name'] = trim(collect([$firstName, $middleName, $lastName])->filter()->implode(' '));
                    $attendee->user()->update($userUpdate);
                }
            }

            AuditLog::record('attendee.updated', $attendee, $data);

            return AttendeeResource::make($attendee);
        });
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Attendee $attendee)
    {
        $this->authorize('delete', $attendee);

        AuditLog::record('attendee.deleted', $attendee);

        $attendee->delete();

        return response()->noContent();
    }

    /**
     * Upload (or replace) the attendee's profile photo.
     */
    public function uploadPhoto(UploadAttendeePhotoRequest $request, Attendee $attendee)
    {
        $service = new ImageUploadService;
        $input = $request->file('photo') ?? $request->input('photo');

        $paths = $service->process(
            $input,
            preset: 'profile_photo',
            directory: "attendees/{$attendee->id}",
            prefix: 'photo',
        );

        $attendee->update(['photo_paths' => $paths]);

        // Sync photo to linked user (same paths, no re-processing)
        if ($attendee->user_id !== null) {
            $attendee->user()->update(['photo_paths' => $paths]);
        }

        AuditLog::record('attendee.photo_updated', $attendee);

        return AttendeeResource::make($attendee);
    }

    /**
     * Remove the attendee's profile photo.
     */
    public function removePhoto(Attendee $attendee)
    {
        $this->authorize('update', $attendee);

        if ($attendee->photo_paths) {
            foreach ($attendee->photo_paths as $path) {
                Storage::disk('public')->delete($path);
            }
        }

        $attendee->update(['photo_paths' => null]);

        // Sync removal to linked user
        if ($attendee->user_id !== null) {
            $attendee->user()->update(['photo_paths' => null]);
        }

        AuditLog::record('attendee.photo_removed', $attendee);

        return AttendeeResource::make($attendee);
    }

    /**
     * Export attendees in Excel or PDF format, optionally filtered by event_id.
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', Attendee::class);

        $query = Attendee::query();

        if ($search = trim((string) $request->string('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        $eventId = $request->input('event_id');
        if ($eventId) {
            $query->whereHas('registrations', function ($q) use ($eventId) {
                $q->where('event_id', $eventId);
            });
        }

        $attendees = $query->with(['union', 'mission', 'church'])->orderBy('last_name')->orderBy('first_name')->get();
        $format = $request->query('format', 'xlsx');

        AuditLog::record('attendee.exported', null, ['format' => $format, 'event_id' => $eventId]);

        if ($format === 'pdf') {
            return $this->exportPdf($attendees, $eventId);
        }

        $export = new AttendeeExport($attendees);

        return Excel::download($export, 'attendees.xlsx');
    }

    private function exportPdf($attendees, $eventId)
    {
        $eventName = null;
        if ($eventId) {
            $event = Event::find($eventId);
            $eventName = $event?->name;
        }

        $pdf = Pdf::loadView('pdf.attendees-report', [
            'attendees' => $attendees,
            'eventName' => $eventName,
        ]);

        return $pdf->download('attendees.pdf');
    }
}
