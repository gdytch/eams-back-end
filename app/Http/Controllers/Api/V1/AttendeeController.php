<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Enums\OrganizationLevel;
use App\Exports\AttendeeExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckAttendeeDuplicatesRequest;
use App\Http\Requests\SaveBreakoutPicksRequest;
use App\Http\Requests\SaveFormAnswersRequest;
use App\Http\Requests\StoreAttendeeRequest;
use App\Http\Requests\UpdateAttendeeRequest;
use App\Http\Requests\UploadAttendeePhotoRequest;
use App\Http\Resources\AttendeeFormResource;
use App\Http\Resources\AttendeeResource;
use App\Http\Resources\EventAttendeeResource;
use App\Jobs\GenerateAttendeeIdCardJob;
use App\Mail\AttendeeAccountInviteMail;
use App\Models\Attendee;
use App\Models\AttendeeDuplicateDismissal;
use App\Models\AuditLog;
use App\Models\Event;
use App\Services\AttendeeDashboardData;
use App\Services\AttendeeDuplicateMergeService;
use App\Services\AttendeeRegistrationService;
use App\Services\BreakoutPickService;
use App\Services\FormAnswerService;
use App\Services\ImageUploadService;
use App\Services\RegistrationCompleteness;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class AttendeeController extends Controller
{
    public function __construct(
        private AttendeeRegistrationService $registration,
        private AttendeeDuplicateMergeService $duplicateMergeService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Attendee::class);

        $query = Attendee::query();

        if (! $request->user()->isSuperAdmin()) {
            $query->where('organization_id', $request->user()->organization_id);
        }

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
            $event = Event::findOrFail($eventId);
            $this->authorize('viewStaffData', $event);
            $query->where('event_id', $eventId);
        }

        $request->validate(['registration_status' => ['nullable', 'in:draft,incomplete,registered']]);

        if ($status = $request->input('registration_status')) {
            $query->withRegistrationStatus($status);
        } else {
            // Drafts are hidden unless asked for; rows without an event have no registration state.
            $query->where(fn ($q) => $q->whereNotNull('first_registered_at')->orWhereNull('event_id'));
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
     * Admin wizard, Basic info save: creates the Draft Attendee for the event on behalf of the person.
     * No User is created; an email owned by an attendee-role User links to it instead.
     */
    public function store(StoreAttendeeRequest $request)
    {
        $data = $request->validated();
        $event = $request->event();
        $admin = $request->user();
        $user = $request->linkedUser();
        unset($data['confirm']);

        if ($user !== null && $existing = $this->registration->userAttendeeIn($user, $event)) {
            return AttendeeResource::make($existing);
        }

        if (! $request->boolean('confirm')) {
            $duplicates = Attendee::matchingName($data['first_name'], $data['last_name'], $event->organization_id, eventId: $event->id);

            if ($duplicates->isNotEmpty()) {
                return response()->json([
                    'message' => 'Possible duplicate attendees found. Pass confirm=true to save anyway.',
                    'duplicates' => AttendeeResource::collection($duplicates),
                ], 409);
            }
        }

        return DB::transaction(function () use ($data, $event, $admin, $user) {
            $latest = $user === null ? null : Attendee::withoutGlobalScopes()->where('user_id', $user->id)->where('organization_id', $event->organization_id)->latest('id')->first();

            if ($latest !== null) {
                // The email is read-only: it mirrors the account, and the rest prefills from the person's latest Attendee.
                $attendee = $this->registration->registerFrom($latest, $event, $admin, confirmed: false);
                $attendee->fill(Arr::except($data, ['email_address', 'event_id']))->save();
            } else {
                $attendee = Attendee::create([
                    ...$data,
                    'email_address' => $user === null ? ($data['email_address'] ?? null) : $user->email,
                    'user_id' => $user?->id,
                    'registered_by' => $admin->id,
                    'created_by' => $admin->id,
                ]);
            }

            AuditLog::record('attendee.created', $attendee, ['on_behalf' => true, 'registered_by' => $admin->id]);

            return AttendeeResource::make($attendee)
                ->additional(['registration' => EventAttendeeResource::make($attendee)])
                ->response()->setStatusCode(201);
        });
    }

    /**
     * Admin wizard, Finish: confirms the Draft. Strict completeness applies.
     */
    public function finish(Attendee $attendee, RegistrationCompleteness $completeness)
    {
        $this->authorize('registerOnBehalf', $attendee);

        return DB::transaction(function () use ($attendee, $completeness) {
            $wasRegistered = $attendee->first_registered_at !== null;

            $attendee->update(['registration_confirmed_at' => now()]);

            if (! $attendee->registration_completed_at) {
                $missing = array_filter(array_diff_key($completeness->steps($attendee), ['review' => 1]));
                // Roll back: the saving hook already cleared the confirmation.
                throw ValidationException::withMessages(['steps' => array_keys($missing)]);
            }

            AuditLog::record('event_registration.finished', $attendee, ['attendee_id' => $attendee->id, 'on_behalf' => true]);

            if (! $wasRegistered) {
                GenerateAttendeeIdCardJob::dispatch($attendee)->afterCommit();
            }

            return AttendeeResource::make($attendee);
        });
    }

    /**
     * Admin wizard, Breakouts: admins may also change picks after registration and may exceed capacity. Audited.
     */
    public function saveBreakouts(SaveBreakoutPicksRequest $request, Attendee $attendee, BreakoutPickService $picks)
    {
        $this->authorize('registerOnBehalf', $attendee);

        return DB::transaction(function () use ($request, $attendee, $picks) {
            $before = $attendee->breakouts()->pluck('breakouts.id')->all();
            $note = $picks->save($attendee, $request->validated('breakout_ids'), ignoreCapacity: true);
            AuditLog::record('attendee.breakout_picks_changed', $attendee, ['before' => $before, 'after' => $request->validated('breakout_ids')]);

            return AttendeeResource::make($attendee->load('breakouts'))->additional(['note' => $note]);
        });
    }

    public function forms(Attendee $attendee, FormAnswerService $answers)
    {
        $this->authorize('view', $attendee);

        return AttendeeFormResource::collection($answers->formsFor($attendee, Event::withoutGlobalScopes()->findOrFail($attendee->event_id)));
    }

    /**
     * Admin wizard, Forms: admins may change answers after registration. Audited.
     */
    public function saveForm(SaveFormAnswersRequest $request, Attendee $attendee, int $form, FormAnswerService $answers, RegistrationCompleteness $completeness)
    {
        return DB::transaction(function () use ($request, $attendee, $answers, $completeness) {
            $changes = $answers->save($attendee, $request->form(), $request->validated('answers'));
            AuditLog::record('attendee.form_answers_changed', $attendee, ['form_id' => $request->form()->id, ...$changes]);

            return AttendeeFormResource::make($answers->withSubmission($attendee, $request->form()))
                ->additional(['steps' => $completeness->steps($attendee->refresh())]);
        });
    }

    /**
     * Skipping is only allowed for an optional form that is not started.
     */
    public function skipForm(Attendee $attendee, int $form, FormAnswerService $answers, RegistrationCompleteness $completeness)
    {
        $this->authorize('registerOnBehalf', $attendee);
        $form = $answers->publishedForm(Event::withoutGlobalScopes()->findOrFail($attendee->event_id), $form);

        abort_if($form->is_required, 422, 'A required form cannot be skipped.');
        abort_if($attendee->formSubmissions()->where('form_id', $form->id)->exists(), 422, 'This form is already started.');

        return AttendeeFormResource::make($answers->withSubmission($attendee, $form))->additional(['steps' => $completeness->steps($attendee)]);
    }

    /**
     * Issue a fresh invite token to an Attendee without a login that has an email.
     */
    public function resendInvite(Attendee $attendee)
    {
        $this->authorize('registerOnBehalf', $attendee);

        abort_if($attendee->user_id !== null || blank($attendee->email_address), 422, 'Only an attendee without a login and with an email can be invited.');

        $attendee->update(['invite_token' => Attendee::generateUniqueInviteToken(), 'invited_at' => now()]);
        Mail::to($attendee->email_address)->send(new AttendeeAccountInviteMail($attendee));
        AuditLog::record('attendee.invite_resent', $attendee, ['email' => $attendee->email_address]);

        return AttendeeResource::make($attendee);
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
        $groupQuery = Attendee::query()->select('id', 'organization_id', 'event_id', 'first_name', 'last_name');

        if ($request->user()->isSuperAdmin() && $request->filled('organization_id')) {
            $groupQuery->where('organization_id', $request->integer('organization_id'));
        }

        $groups = $groupQuery->get()
            ->groupBy(fn (Attendee $attendee) => $attendee->event_id.'|'.$attendee->organization_id.'|'.Attendee::normalizeName($attendee->first_name, $attendee->last_name))
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
                ->with(['union', 'mission', 'church', 'event', 'attendanceRecords' => fn ($records) => $records->counted()])
                ->orderBy('created_at')
                ->get();

            $members = $attendees->map(function (Attendee $attendee) use ($request) {
                $attendee->setAttribute('duplicate_registration_count', 1);
                $attendee->setAttribute('duplicate_attendance_count', $attendee->attendanceRecords->count());
                $attendee->setAttribute('duplicate_event_names', array_filter([$attendee->event?->name]));

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
            $this->activeDuplicateAttendees([$data['primary_attendee_id'], ...$data['duplicate_attendee_ids']])
                ->each(fn (Attendee $attendee) => $this->authorize('merge', $attendee));
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
    public function showById(Request $request, int $attendeeId, AttendeeDashboardData $dashboardData)
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
            $event = Event::findOrFail($eventId);
            abort_unless($request->user()->can('viewStaffData', $event)
                || ($attendee->user_id === $request->user()->id && $attendee->event_id === $event->id), 403);
            $eventStats = $this->eventStats($attendee, $event);
        }

        $additional = [
            'event_stats' => $eventStats,
            'merged_from_id' => $mergedFromId,
        ];

        if ($request->query('include') === 'dashboard') {
            $additional['profile_dashboard'] = $this->profileDashboard($request, $attendee, $dashboardData);
        }

        return AttendeeResource::make($attendee)->additional($additional);
    }

    private function eventStats(Attendee $attendee, Event $event): ?array
    {
        if ($attendee->event_id !== $event->id) {
            return null;
        }

        $registration = $attendee->loadMissing(['attendanceRecords', 'event.sessions']);

        $checkedInSessionIds = $registration->attendanceRecords
            ->filter(fn ($record) => $record->check_in_at !== null)
            ->pluck('event_session_id')
            ->unique()
            ->values()
            ->all();
        $absentCount = $registration->event->sessions
            ->filter(fn ($session) => $session->attendanceStatus(in_array($session->id, $checkedInSessionIds, true), $registration->first_registered_at) === AttendanceStatus::Absent)
            ->count();

        return [
            'registered' => true,
            'registered_at' => $registration->registered_at,
            'present_count' => count($checkedInSessionIds),
            'absent_count' => $absentCount,
            'total_sessions' => $registration->event->sessions->count(),
            'registration_count' => $attendee->personRegistrations()->count(),
            'qr_token' => $registration->qr_token,
        ];
    }

    private function profileDashboard(Request $request, Attendee $attendee, AttendeeDashboardData $dashboardData): array
    {
        $attendee->loadMissing('user');
        $viewer = $request->user();
        $registrationQuery = $attendee->personRegistrations()
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

        return $dashboardData->profileDashboard($attendee, $registrationQuery->get());
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAttendeeRequest $request, Attendee $attendee)
    {
        $data = $request->validated();
        $override = (bool) ($data['override_duplicate'] ?? false);
        unset($data['override_duplicate']);

        // A linked attendee's email mirrors the user account and is read-only.
        if ($attendee->user_id !== null) {
            unset($data['email_address']);
        }

        $nameChanged = array_key_exists('first_name', $data) || array_key_exists('last_name', $data);
        if ($nameChanged && ! $override) {
            $firstName = $data['first_name'] ?? $attendee->first_name;
            $lastName = $data['last_name'] ?? $attendee->last_name;
            $normalizedName = Attendee::normalizeName($firstName, $lastName);

            if ($normalizedName !== Attendee::normalizeName($attendee->first_name, $attendee->last_name)) {
                $duplicates = Attendee::matchingName($firstName, $lastName, $attendee->organization_id, $attendee->id, $attendee->event_id);

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
            if ($attendee->first_registered_at !== null && (
                isset($data['first_name']) ||
                isset($data['middle_name']) ||
                isset($data['last_name']) ||
                isset($data['union_id']) ||
                isset($data['mission_id']) ||
                isset($data['organization_level'])
            )) {
                GenerateAttendeeIdCardJob::dispatch($attendee);
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

        // The row is gone after this, and DB cascades take its attendance, picks, answers and certificates;
        // the snapshot keeps who was deleted.
        AuditLog::record('attendee.deleted', $attendee, [
            'snapshot' => $attendee->only(['event_id', 'first_name', 'middle_name', 'last_name', 'email_address', 'first_registered_at']),
        ]);

        $attendee->delete();

        return response()->noContent();
    }

    /**
     * Upload (or replace) the attendee's profile photo.
     */
    public function uploadPhoto(UploadAttendeePhotoRequest $request, Attendee $attendee, ImageUploadService $imageUploadService)
    {
        $input = $request->file('photo') ?? $request->input('photo');
        $paths = $imageUploadService->uploadProfilePhoto($input, "attendees/{$attendee->id}");

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
    public function removePhoto(Attendee $attendee, ImageUploadService $imageUploadService)
    {
        $this->authorize('update', $attendee);

        $imageUploadService->deleteProfilePhoto($attendee->photo_paths);

        $attendee->update(['photo_paths' => null]);

        // Sync removal to linked user
        if ($attendee->user_id !== null) {
            $attendee->user()->update(['photo_paths' => null]);
        }

        AuditLog::record('attendee.photo_removed', $attendee);

        return AttendeeResource::make($attendee);
    }

    /**
     * Export one event's attendees in Excel or PDF format.
     */
    public function export(Request $request)
    {
        $this->authorize('viewAny', Attendee::class);

        $request->validate([
            'event_id' => ['required', 'integer'],
            'format' => ['nullable', 'in:xlsx,pdf'],
            'registration_status' => ['nullable', 'in:draft,incomplete,registered'],
        ]);

        $event = Event::findOrFail($request->integer('event_id'));
        $this->authorize('viewStaffData', $event);

        $query = Attendee::query()->where('event_id', $event->id);

        if (! $request->user()->isSuperAdmin()) {
            $query->where('organization_id', $request->user()->organization_id);
        }

        if ($search = trim((string) $request->string('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('registration_status')) {
            $query->withRegistrationStatus($status);
        }

        $attendees = $query->with(['union', 'mission', 'church'])->orderBy('last_name')->orderBy('first_name')->get();
        $format = $request->query('format', 'xlsx');

        AuditLog::record('attendee.exported', null, ['format' => $format, 'event_id' => $event->id]);
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
            $attendee->registration_status_label = ['registered' => 'Fully registered', 'incomplete' => 'Incomplete', 'draft' => 'Draft'][$attendee->registrationStatus()] ?? '';
        });
        if ($format === 'pdf') {
            return $this->exportPdf($attendees, $event);
        }

        $export = new AttendeeExport($attendees);

        return Excel::download($export, 'attendees.xlsx');
    }

    private function exportPdf($attendees, Event $event)
    {
        $pdf = Pdf::loadView('pdf.attendees-report', [
            'attendees' => $attendees,
            'eventName' => $event->name,
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

        if ($attendees->pluck('event_id')->unique()->count() !== 1) {
            abort(422, 'Attendees must be duplicates registered for the same event.');
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
