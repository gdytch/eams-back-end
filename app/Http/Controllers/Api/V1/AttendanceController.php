<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\ManualAttendanceRequest;
use App\Http\Requests\ScanAttendanceRequest;
use App\Http\Requests\SyncAttendanceBatchRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\EventSessionRosterEntryResource;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    /**
     * Record attendance from a QR scan.
     */
    public function scan(ScanAttendanceRequest $request)
    {
        if ($request->boolean('test_mode')) {
            abort_unless($request->user()->isSuperAdmin(), 403);
        }

        $event = Event::findOrFail($request->validated('event_id'));
        $slot = $this->resolveSlot($event, $request->validated());

        $attendee = Attendee::where('event_id', $event->id)
            ->where('qr_token', $request->validated('qr_token'))
            ->first();

        if ($attendee === null) {
            throw ValidationException::withMessages([
                'qr_token' => 'This QR code is not registered for this event.',
            ]);
        }

        if ($request->boolean('test_mode')) {
            $attendee->load(['union', 'mission']);
            $attendance = new AttendanceRecord([
                'attendee_id' => $attendee->id,
                AttendanceRecord::slotColumn($slot) => $slot->id,
                'check_in_at' => now(),
                'method' => AttendanceMethod::Qr,
                'recorded_by' => $request->user()->id,
            ]);
            $attendance->setRelation('attendee', $attendee);

            return AttendanceRecordResource::make($attendance)
                ->additional([
                    'test_mode' => true,
                    'message' => 'Test scan successful. No attendance recorded.',
                ])
                ->response()
                ->setStatusCode(200);
        }

        return $this->recordAttendance($request, $attendee, $slot, AttendanceMethod::Qr, flags: $this->flags($request->validated()));
    }

    /**
     * Record attendance manually (fallback when QR scanning is unavailable).
     */
    public function manual(ManualAttendanceRequest $request)
    {
        $event = Event::findOrFail($request->validated('event_id'));
        $slot = $this->resolveSlot($event, $request->validated());
        $attendee = $this->resolveAttendee($event, $request->validated())->load(['union', 'mission']);

        return $this->recordAttendance($request, $attendee, $slot, AttendanceMethod::Manual, flags: $this->flags($request->validated()));
    }

    /**
     * Record a check-out for an existing attendance record (only when the event requires it).
     */
    public function checkOut(Request $request, AttendanceRecord $attendanceRecord)
    {
        $this->authorize('checkOut', $attendanceRecord);

        $event = $attendanceRecord->attendee->event;

        if ($attendanceRecord->breakout_id !== null) {
            throw ValidationException::withMessages(['attendance_record' => 'Breakouts have no check-out.']);
        }

        if (! $event->requires_check_out) {
            throw ValidationException::withMessages([
                'event' => 'This event does not require check-out.',
            ]);
        }

        $attendanceRecord->update(['check_out_at' => now()]);

        AuditLog::record('attendance.checked_out', $attendanceRecord);

        return AttendanceRecordResource::make($attendanceRecord);
    }

    /**
     * List attendance records for a given event session.
     */
    public function forSession(Request $request, Event $event, EventSession $session)
    {
        $this->authorize('viewRoster', $session);
        abort_unless($session->event_id === $event->id, 404);

        $input = $request->validate([
            'record_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $records = AttendanceRecord::whereHas('attendee', fn ($q) => $q->where('event_id', $event->id))
            ->where('event_session_id', $session->id)
            ->when(isset($input['record_id']), fn ($query) => $query->whereKey($input['record_id']))
            ->with('attendee');
        if ($request->has('sort_by')) {
            $sortBy = $request->input('sort_by');
            $sortOrder = strtolower($request->input('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc';

            if ($sortBy === 'last_name') {
                $records->join('attendees', 'attendance_records.attendee_id', '=', 'attendees.id')
                    ->orderBy('attendees.last_name', $sortOrder)
                    ->select('attendance_records.*');
            } else {
                $records->orderBy('check_in_at', 'desc');
            }
        } else {
            $records->orderBy('check_in_at', 'desc');
        }

        $records = $records->paginate($request->input('per_page', 15));

        return AttendanceRecordResource::collection($records);
    }

    /**
     * List Expected Attendees plus walk-ins for a session with their status (Present / Absent / Upcoming), sorted by check-in.
     */
    public function roster(Request $request, Event $event, EventSession $session)
    {
        $this->authorize('viewRoster', $session);

        $status = strtolower((string) $request->query('status', 'all'));
        $search = $request->query('search', '');

        if (! in_array($status, ['all', 'present', 'not_checked_in'], true)) {
            throw ValidationException::withMessages([
                'status' => 'The status must be one of: all, present, not_checked_in.',
            ]);
        }

        $registrations = $event->attendees()
            ->inAttendanceOf($session)
            ->with(['union', 'mission'])
            ->addSelect([
                'session_check_in_at' => AttendanceRecord::select('check_in_at')
                    ->whereColumn('attendee_id', 'attendees.id')
                    ->where('event_session_id', $session->id)
                    ->limit(1),
                'session_check_out_at' => AttendanceRecord::select('check_out_at')
                    ->whereColumn('attendee_id', 'attendees.id')
                    ->where('event_session_id', $session->id)
                    ->limit(1),
            ])
            ->withCasts([
                'session_check_in_at' => 'datetime',
                'session_check_out_at' => 'datetime',
            ])
            ->when($status === 'present', fn ($q) => $q->presentAt($session))
            ->when($status === 'not_checked_in', fn ($q) => $q->notCheckedInAt($session))
            ->when($search !== '', fn ($q) => $q->where(function ($query) use ($search) {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->when($status === 'not_checked_in', fn ($q) => $q->orderBy('last_name')->orderBy('first_name'), fn ($q) => $q->orderByRaw('session_check_in_at is null, session_check_in_at asc'))
            ->paginate($request->input('per_page', 15));

        $registrations->each(fn ($reg) => $reg->setAttribute(
            'attendance_status',
            $session->attendanceStatus($reg->session_check_in_at !== null, $reg->first_registered_at),
        ));

        return EventSessionRosterEntryResource::collection($registrations)->additional([
            'event_id' => $event->id,
            'session_id' => $session->id,
            'status' => $status,
        ]);
    }

    /**
     * Process a batch of attendance scans with per-item error handling.
     */
    public function syncBatch(SyncAttendanceBatchRequest $request)
    {
        $scans = $request->validated('scans');
        $results = [];

        foreach ($scans as $scan) {
            $result = [
                'client_ref' => $scan['client_ref'],
                'status' => 'error',
                'message' => '',
                'attendance_record_id' => null,
            ];

            try {
                $event = Event::findOrFail($scan['event_id']);
                $slot = $this->resolveSlot($event, $scan);

                // Check authorization for this event; the scan's client time is not trusted for the archive check.
                $decision = Gate::forUser($request->user())->inspect('create', [AttendanceRecord::class, $event]);
                if ($decision->denied()) {
                    $archived = $decision->code() === 'event_archived';
                    $result['status'] = $archived ? 'event_archived' : 'error';
                    $result['message'] = $archived ? $decision->message() : 'Unauthorized to record attendance for this event.';
                    $results[] = $result;

                    continue;
                }

                // Resolve the attendee.
                if (! empty($scan['qr_token'])) {
                    $attendee = Attendee::where('event_id', $event->id)
                        ->where('qr_token', $scan['qr_token'])
                        ->first();

                    if ($attendee === null) {
                        $result['message'] = 'This QR code is not registered for this event.';
                        $results[] = $result;

                        continue;
                    }
                } else {
                    $attendee = $this->resolveAttendee($event, $scan);
                }

                $method = AttendanceMethod::from($scan['method']);
                $scannedAt = ! empty($scan['scanned_at']) ? Carbon::parse($scan['scanned_at']) : null;

                // Process the attendance in its own transaction.
                $attendance = DB::transaction(function () use ($attendee, $slot, $method, $request, $scannedAt, $scan) {
                    return $this->recordAttendance($request, $attendee, $slot, $method, $scannedAt, true, $this->flags($scan));
                });

                $result['status'] = 'created';
                $result['message'] = 'Attendance recorded successfully.';
                $result['attendance_record_id'] = $attendance->id;
                $result['incomplete'] = $attendee->registration_completed_at === null;
            } catch (ValidationException $e) {
                // Handle validation exceptions (already checked in, session not open, etc).
                $messages = $e->errors();
                $allMessages = collect($messages)->flatten()->toArray();
                $firstMessage = reset($allMessages) ?: 'Validation error.';

                // Determine if it's a duplicate.
                if (stripos((string) $firstMessage, 'Already Checked In') !== false) {
                    $result['status'] = 'duplicate';
                } else {
                    $result['status'] = 'error';
                }

                $result['message'] = $firstMessage;
            } catch (ModelNotFoundException $e) {
                $result['status'] = 'error';
                $result['message'] = 'Attendee not found for this event.';
            } catch (\Exception $e) {
                $result['status'] = 'error';
                $result['message'] = $e->getMessage() ?: 'An unexpected error occurred.';
            }

            $results[] = $result;
        }

        AuditLog::record('attendance.batch_sync', null, ['scan_count' => count($scans)]);

        return response()->json(['results' => $results], 200);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveAttendee(Event $event, array $input): Attendee
    {
        return Attendee::where('event_id', $event->id)->findOrFail($input['attendee_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveSlot(Event $event, array $input): EventSession|Breakout
    {
        return empty($input['breakout_id'])
            ? EventSession::where('event_id', $event->id)->findOrFail($input['session_id'])
            : Breakout::where('event_id', $event->id)->findOrFail($input['breakout_id']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{override: bool, late: bool, walk_in: bool}
     */
    private function flags(array $input): array
    {
        return [
            'override' => (bool) ($input['override'] ?? false),
            'late' => (bool) ($input['late'] ?? false),
            'walk_in' => (bool) ($input['walk_in'] ?? false),
        ];
    }

    /**
     * Checkers check in only while the slot is open; early and late check-ins need an admin and the matching flag.
     *
     * @param  array{override: bool, late: bool, walk_in: bool}  $flags
     * @return array{0: ?string, 1: ?string} [early|late|null, error message]
     */
    private function timing(EventSession|Breakout $slot, Carbon $at, bool $isAdmin, array $flags): array
    {
        if ($at->lessThan($slot->checkInOpensAt())) {
            return $isAdmin && $flags['override'] ? ['early', null] : [null, 'Attendance for this '.$this->label($slot).' has not opened yet.'];
        }

        if ($at->greaterThan($slot->endsAt())) {
            return $isAdmin && $flags['late']
                ? ['late', null]
                : [null, 'This '.$this->label($slot).' has ended.'.($isAdmin ? ' Confirm as a late check-in.' : ' Only an event admin can record a late check-in.')];
        }

        return [null, null];
    }

    private function label(EventSession|Breakout $slot): string
    {
        return $slot instanceof Breakout ? 'breakout' : 'session';
    }

    /**
     * @param  array{override: bool, late: bool, walk_in: bool}  $flags
     */
    private function recordAttendance(
        Request $request,
        Attendee $attendee,
        EventSession|Breakout $slot,
        AttendanceMethod $method,
        ?Carbon $scannedAt = null,
        bool $isBatchSync = false,
        array $flags = ['override' => false, 'late' => false, 'walk_in' => false]
    ) {
        if ($attendee->first_registered_at === null) {
            throw ValidationException::withMessages(['attendee_id' => 'Registration incomplete.']);
        }

        $column = AttendanceRecord::slotColumn($slot);
        $existing = AttendanceRecord::where('attendee_id', $attendee->id)
            ->where($column, $slot->id)
            ->first();

        if ($existing !== null && $existing->check_in_at !== null) {
            throw ValidationException::withMessages([
                $method === AttendanceMethod::Qr ? 'qr_token' : 'attendee_id' => 'Already Checked In.',
            ]);
        }

        $user = $request->user();
        $isAdmin = $user->isSuperAdmin() || $user->isOrgAdmin();
        // The scan's own time decides early / late, so an offline scan synced after the slot ended still counts.
        $at = $scannedAt === null || $scannedAt->isFuture() ? now() : $scannedAt;

        $walkIn = $slot instanceof Breakout && ! $attendee->breakouts()->whereKey($slot->id)->exists();
        if ($walkIn && ! ($isAdmin && $flags['walk_in'])) {
            throw ValidationException::withMessages([
                'attendee_id' => $isAdmin
                    ? 'This Attendee did not pick this breakout. Confirm as a walk-in.'
                    : 'This Attendee did not pick this breakout. Only an event admin can admit a walk-in.',
            ]);
        }

        [$timing, $error] = $this->timing($slot, $at, $isAdmin, $flags);
        if ($error !== null) {
            throw ValidationException::withMessages([$column => $error]);
        }

        try {
            return DB::transaction(function () use ($existing, $attendee, $slot, $column, $method, $user, $timing, $walkIn, $at, $isAdmin, $flags, $isBatchSync) {
                $attendance = $existing ?? new AttendanceRecord(['attendee_id' => $attendee->id, $column => $slot->id]);

                $attendance->check_in_at = $at;
                $attendance->method = $method;
                $attendance->recorded_by = $user->id;
                $attendance->is_walk_in = $walkIn;
                $attendance->save();

                AuditLog::record(
                    match (true) {
                        $walkIn => 'attendance.walk_in',
                        $timing === 'late' => 'attendance.checked_in_late',
                        $timing === 'early' => 'attendance.checked_in_override',
                        default => 'attendance.checked_in',
                    },
                    $attendance,
                    ['method' => $method->value, $slot instanceof Breakout ? 'breakout_id' : 'session_id' => $slot->id, 'late' => $timing === 'late', 'early' => $timing === 'early'],
                );

                if ($slot instanceof Breakout) {
                    $this->checkInOverlappingSessions($attendee, $slot, $method, $user->id, $at, $isAdmin, $flags);
                }

                if ($isBatchSync) {
                    return $attendance;
                }

                return AttendanceRecordResource::make($attendance->load(['attendee.union', 'attendee.mission']))
                    ->additional($attendee->registration_completed_at === null ? ['incomplete' => true, 'note' => 'Registration incomplete.'] : [])
                    ->response()
                    ->setStatusCode(201);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent scan of the same slot won the unique index.
            throw ValidationException::withMessages([$column => 'Already Checked In.']);
        }
    }

    /**
     * A breakout check-in also checks in to each same-date session it overlaps (half-open), where this actor could check in directly. The earliest check-in wins.
     *
     * @param  array{override: bool, late: bool, walk_in: bool}  $flags
     */
    private function checkInOverlappingSessions(Attendee $attendee, Breakout $breakout, AttendanceMethod $method, int $userId, Carbon $at, bool $isAdmin, array $flags): void
    {
        $sessions = $breakout->event->sessions()->whereDate('session_date', $breakout->date->toDateString())->get()
            ->filter(fn (EventSession $session) => $session->startsAt()->lt($breakout->endsAt()) && $breakout->startsAt()->lt($session->endsAt()));

        foreach ($sessions as $session) {
            $session->setRelation('event', $breakout->event);
            if ($this->timing($session, $at, $isAdmin, $flags)[1] !== null) {
                continue;
            }

            $record = AttendanceRecord::firstOrNew(['attendee_id' => $attendee->id, 'event_session_id' => $session->id]);
            if ($record->check_in_at !== null && $record->check_in_at->lessThanOrEqualTo($at)) {
                continue;
            }

            $record->fill(['check_in_at' => $at, 'method' => $method, 'recorded_by' => $userId, 'via_breakout_id' => $breakout->id])->save();

            AuditLog::record('attendance.checked_in_via_breakout', $record, [
                'method' => $method->value,
                'session_id' => $session->id,
                'breakout_id' => $breakout->id,
            ]);
        }
    }
}
