<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationLevel;
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
use App\Models\AttendeeDuplicateDismissal;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\AttendeeDuplicateMergeService;
use App\Services\AttendeeInvitationService;
use App\Services\ImageUploadService;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class AttendeeController extends Controller
{
    public function __construct(
        private AttendeeInvitationService $invitationService,
        private AttendeeDuplicateMergeService $duplicateMergeService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Attendee::class);

        $query = Attendee::query();

        if ($organizationId = $request->integer('organization_id')) {
            $query->where('organization_id', $organizationId);
        }

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
                $query->orderBy('union_id', $sortOrder)
                    ->orderBy('mission_id', $sortOrder);
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
            $organizationId = $data['organization_id'] ?? $request->user()->organization_id;
            $duplicates = Attendee::matchingName($data['first_name'], $data['last_name'], $organizationId);

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

                // Temporarily disabled sending welcome email to because of email sending rate limits. Can be re-enabled later if needed.
                // if ($attendee->email_address) {
                //     Mail::to($attendee->email_address)->send(new EventRegistrationWelcomeMail($registration));
                // }
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
        $this->authorize('viewAny', Attendee::class);

        $duplicates = Attendee::matchingName(
            $request->validated('first_name'),
            $request->validated('last_name'),
        );

        return AttendeeResource::collection($duplicates);
    }

    /**
     * Return active, unresolved exact-name duplicate groups for review.
     */
    public function duplicates(Request $request)
    {
        $this->authorizeDuplicateResolution($request);

        $perPage = min(max($request->integer('per_page', 10), 1), 50);
        $page = max($request->integer('page', 1), 1);
        $groupQuery = Attendee::query()->select('id', 'organization_id', 'first_name', 'last_name');

        if ($request->user()->isSuperAdmin() && $request->filled('organization_id')) {
            $groupQuery->where('organization_id', $request->integer('organization_id'));
        }

        $groups = $groupQuery->get()
            ->groupBy(fn (Attendee $attendee) => $attendee->organization_id.'|'.Attendee::normalizeName($attendee->first_name, $attendee->last_name))
            ->filter(fn ($attendees) => $attendees->count() > 1)
            ->map(function ($attendees) {
                $first = $attendees->first();

                return (object) [
                    'organization_id' => $first->organization_id,
                    'normalized_name' => Attendee::normalizeName($first->first_name, $first->last_name),
                    'ids' => $attendees->pluck('id'),
                ];
            })
            ->filter(fn ($group) => ! $this->allPairsDismissed($group->organization_id, $group->normalized_name, $group->ids))
            ->sortBy('normalized_name')
            ->values();

        $pageGroups = $groups->slice(($page - 1) * $perPage, $perPage)->values();
        $organizationNames = DB::table('organizations')
            ->whereIn('id', $pageGroups->pluck('organization_id')->unique())
            ->pluck('name', 'id');
        $data = $pageGroups->map(function ($group) use ($request, $organizationNames) {
            $attendees = Attendee::query()
                ->whereIn('id', $group->ids)
                ->with(['union', 'mission', 'church', 'registrations.event', 'registrations.attendanceRecords'])
                ->orderBy('created_at')
                ->get();

            $members = $attendees->map(function (Attendee $attendee) use ($request) {
                $attendee->setAttribute('duplicate_registration_count', $attendee->registrations->count());
                $attendee->setAttribute('duplicate_attendance_count', $attendee->registrations->sum(fn ($registration) => $registration->attendanceRecords->count()));
                $attendee->setAttribute('duplicate_event_names', $attendee->registrations->pluck('event.name')->filter()->unique()->values()->all());
                $attendee->setAttribute('duplicate_has_linked_account', $attendee->user_id !== null);

                return (new AttendeeResource($attendee))->resolve($request);
            });

            return [
                'organization_id' => $group->organization_id,
                'organization_name' => $organizationNames->get($group->organization_id),
                'normalized_name' => $group->normalized_name,
                'attendee_count' => $attendees->count(),
                'attendees' => $members,
                'has_account_conflict' => $attendees->whereNotNull('user_id')->pluck('user_id')->unique()->count() > 1,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $groups->count(),
                'last_page' => max((int) ceil($groups->count() / $perPage), 1),
            ],
        ]);
    }

    public function dismissDuplicates(Request $request)
    {
        $this->authorizeDuplicateResolution($request);
        $data = $request->validate(['attendee_ids' => ['required', 'array', 'min:2'], 'attendee_ids.*' => ['integer', 'distinct']]);
        $attendees = $this->activeDuplicateAttendees($data['attendee_ids']);
        $first = $attendees->first();
        $fingerprint = $this->duplicateFingerprint(Attendee::normalizeName($first->first_name, $first->last_name));

        foreach ($attendees as $index => $first) {
            foreach ($attendees->slice($index + 1) as $second) {
                [$oneId, $twoId] = collect([$first->id, $second->id])->sort()->values()->all();
                AttendeeDuplicateDismissal::query()->firstOrCreate([
                    'organization_id' => $first->organization_id,
                    'attendee_one_id' => $oneId,
                    'attendee_two_id' => $twoId,
                    'name_fingerprint' => $fingerprint,
                ], [
                    'dismissed_by' => $request->user()->id,
                    'dismissed_at' => now(),
                ]);
            }
        }

        AuditLog::record('attendee_duplicates.dismissed', $attendees->first(), ['attendee_ids' => $attendees->pluck('id')->all()]);

        return response()->noContent();
    }

    public function mergeDuplicates(Request $request)
    {
        $this->authorizeDuplicateResolution($request);
        $data = $request->validate([
            'primary_attendee_id' => ['required', 'integer'],
            'duplicate_attendee_ids' => ['required', 'array', 'min:1'],
            'duplicate_attendee_ids.*' => ['integer', 'distinct'],
        ]);

        if (in_array($data['primary_attendee_id'], $data['duplicate_attendee_ids'], true)) {
            return response()->json(['message' => 'Primary attendee cannot also be a duplicate source.'], 422);
        }

        try {
            $this->activeDuplicateAttendees([$data['primary_attendee_id'], ...$data['duplicate_attendee_ids']]);
            $attendee = $this->duplicateMergeService->merge(
                $request->user(),
                $data['primary_attendee_id'],
                $data['duplicate_attendee_ids'],
            );
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return AttendeeResource::make($attendee);
    }

    /**
     * Display the specified resource.
     */
    public function showById(Request $request, int $attendeeId)
    {
        $attendee = Attendee::withoutGlobalScopes()->findOrFail($attendeeId);
        $mergedFromId = null;
        if ($attendee->merged_into_id !== null) {
            $mergedFromId = $attendee->id;
            $attendee = Attendee::query()->findOrFail($attendee->merged_into_id);
        }

        if (! $request->user()->isSuperAdmin()
            && $request->user()->organization_id !== $attendee->organization_id
            && $attendee->user_id !== $request->user()->id) {
            abort(404);
        }

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
                    ->filter(fn ($record) => $record->check_in_at !== null)
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

        $additional = [
            'event_stats' => $eventStats,
            'merged_from_id' => $mergedFromId,
        ];

        if ($request->query('include') === 'dashboard') {
            $additional['profile_dashboard'] = $this->profileDashboard($request, $attendee);
        }

        return AttendeeResource::make($attendee)->additional($additional);
    }

    private function profileDashboard(Request $request, Attendee $attendee): array
    {
        $attendee->loadMissing('user');
        $account = $attendee->user;
        $viewer = $request->user();
        $registrationQuery = $attendee->registrations()
            ->with(['event.sessions', 'attendanceRecords'])
            ->whereHas('event')
            ->orderByDesc('registered_at');

        if (! $viewer->isSuperAdmin() && ! $viewer->isAttendee()) {
            $registrationQuery->whereHas('event', fn ($query) => $query->where('organization_id', $viewer->organization_id));
        }

        if ($viewer->isChecker()) {
            $accessibleEventIds = $viewer->accessibleEvents()->pluck('events.id');
            if ($accessibleEventIds->isNotEmpty()) {
                $registrationQuery->whereIn('event_id', $accessibleEventIds);
            }
        }

        $registrations = $registrationQuery->get()
            ->map(function (EventRegistration $registration) {
                $event = $registration->event;
                $completedSessionIds = $event->sessions
                    ->filter(fn ($session) => $session->endsAt()->isPast())
                    ->pluck('id');
                $attendedSessions = $registration->attendanceRecords
                    ->whereNotNull('check_in_at')
                    ->pluck('event_session_id')
                    ->unique()
                    ->intersect($completedSessionIds)
                    ->count();
                $completedSessions = $completedSessionIds->count();
                $attendanceBySession = $registration->attendanceRecords->keyBy('event_session_id');
                $sessionRows = $event->sessions
                    ->sortBy(fn ($session) => $session->session_date->toDateString().' '.$session->start_time)
                    ->values()
                    ->map(function ($session) use ($attendanceBySession) {
                        $attendance = $attendanceBySession->get($session->id);

                        return [
                            'id' => $session->id,
                            'name' => $session->name,
                            'description' => $session->description,
                            'session_date' => $session->session_date?->toDateString(),
                            'start_time' => $session->start_time,
                            'end_time' => $session->end_time,
                            'status' => $attendance?->check_in_at !== null
                                ? 'present'
                                : ($session->endsAt()->isPast() ? 'absent' : 'upcoming'),
                            'check_in_at' => $attendance?->check_in_at,
                            'check_out_at' => $attendance?->check_out_at,
                            'method' => $attendance?->method,
                        ];
                    });

                return [
                    'id' => $registration->id,
                    'registered_at' => $registration->registered_at,
                    'event' => [
                        'id' => $event->id,
                        'name' => $event->name,
                        'start_date' => $event->start_date?->toDateString(),
                        'end_date' => $event->end_date?->toDateString(),
                        'venue' => $event->venue,
                        'status' => $event->status,
                    ],
                    'attendance' => [
                        'sessions_total' => $event->sessions->count(),
                        'completed_sessions' => $completedSessions,
                        'attended_sessions' => $attendedSessions,
                        'absent_sessions' => $completedSessions - $attendedSessions,
                        'rate' => $completedSessions > 0
                            ? round($attendedSessions / $completedSessions * 100, 1)
                            : null,
                        'sessions' => $sessionRows->all(),
                    ],
                ];
            });

        $completedSessions = $registrations->sum(fn (array $registration) => $registration['attendance']['completed_sessions']);
        $attendedSessions = $registrations->sum(fn (array $registration) => $registration['attendance']['attended_sessions']);

        return [
            'account' => [
                'linked' => $account !== null,
                'email' => $account?->email,
                'email_verified' => $account?->hasVerifiedEmail(),
                'invite_status' => ($account?->invite_token ?? $attendee->invite_token) !== null
                    ? 'pending'
                    : (($account?->invited_at ?? $attendee->invited_at) !== null ? 'accepted' : null),
            ],
            'registrations' => $registrations->all(),
            'stats' => [
                'registration_count' => $registrations->count(),
                'completed_sessions' => $completedSessions,
                'attended_sessions' => $attendedSessions,
                'attendance_rate' => $completedSessions > 0
                    ? round($attendedSessions / $completedSessions * 100, 1)
                    : null,
            ],
        ];
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAttendeeRequest $request, Attendee $attendee)
    {
        $data = $request->validated();
        $override = (bool) ($data['override_duplicate'] ?? false);
        unset($data['override_duplicate']);

        $nameChanged = array_key_exists('first_name', $data) || array_key_exists('last_name', $data);
        if ($nameChanged && ! $override) {
            $firstName = $data['first_name'] ?? $attendee->first_name;
            $lastName = $data['last_name'] ?? $attendee->last_name;
            $normalizedName = Attendee::normalizeName($firstName, $lastName);

            if ($normalizedName !== Attendee::normalizeName($attendee->first_name, $attendee->last_name)) {
                $duplicates = Attendee::matchingName($firstName, $lastName, $attendee->organization_id, $attendee->id);

                if ($duplicates->isNotEmpty()) {
                    return response()->json([
                        'message' => 'Potential duplicate attendees found. Pass override_duplicate=true to update anyway.',
                        'duplicates' => AttendeeResource::collection($duplicates),
                    ], 409);
                }
            }
        }

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

            // regenerate ID card if the attendee's name, union, mission, organization_leve has changed and they have an existing registration
            if ($attendee->registrations()->exists() && (
                isset($data['first_name']) ||
                isset($data['middle_name']) ||
                isset($data['last_name']) ||
                isset($data['union_id']) ||
                isset($data['mission_id']) ||
                isset($data['organization_level'])
            )) {
                foreach ($attendee->registrations as $registration) {
                    GenerateAttendeeIdCardJob::dispatch($registration);
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

        if (Attendee::withoutGlobalScopes()->where('merged_into_id', $attendee->id)->exists()) {
            return response()->json(['message' => 'Cannot delete an attendee with merged source records.'], 409);
        }

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
        $attendees->each(function ($attendee) {
            $organizationName = $attendee->union?->code ?? '';
            if ($attendee->organization_level !== null) {
                if ($attendee->organization_level === OrganizationLevel::Mission && $attendee->mission !== null) {
                    $organizationName = $attendee->mission->code;
                }
            } elseif ($attendee->mission) {
                $organizationName = $attendee->mission->code;
            }
            $attendee->organization_name = $organizationName;
        });
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

    private function authorizeDuplicateResolution(Request $request): void
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->isOrgAdmin(), 403);
    }

    /** @param array<int, int|string> $attendeeIds */
    private function activeDuplicateAttendees(array $attendeeIds)
    {
        $ids = collect($attendeeIds)->map(fn ($id) => (int) $id)->unique()->values();
        $attendees = Attendee::query()->whereIn('id', $ids)->get();

        if ($attendees->count() !== $ids->count()) {
            abort(404);
        }

        if ($attendees->pluck('organization_id')->unique()->count() !== 1
            || $attendees->map(fn (Attendee $attendee) => Attendee::normalizeName($attendee->first_name, $attendee->last_name))->unique()->count() !== 1) {
            abort(422, 'Attendees must be active duplicates from one organization.');
        }

        return $attendees->sortBy('id')->values();
    }

    private function allPairsDismissed(int $organizationId, string $normalizedName, $attendeeIds): bool
    {
        $ids = collect($attendeeIds)->map(fn ($id) => (int) $id)->sort()->values();
        if ($ids->count() < 2) {
            return false;
        }

        $dismissed = AttendeeDuplicateDismissal::query()
            ->where('organization_id', $organizationId)
            ->where('name_fingerprint', $this->duplicateFingerprint($normalizedName))
            ->get()
            ->mapWithKeys(fn (AttendeeDuplicateDismissal $dismissal) => [
                "{$dismissal->attendee_one_id}:{$dismissal->attendee_two_id}" => true,
            ]);

        foreach ($ids as $index => $firstId) {
            foreach ($ids->slice($index + 1) as $secondId) {
                if (! $dismissed->has("{$firstId}:{$secondId}")) {
                    return false;
                }
            }
        }

        return true;
    }

    private function duplicateFingerprint(string $normalizedName): string
    {
        return hash('sha256', $normalizedName);
    }
}
