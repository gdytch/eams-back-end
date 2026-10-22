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
use App\Models\Event;
use App\Models\EventSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $session = EventSession::where('event_id', $event->id)->findOrFail($request->validated('session_id'));

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
                'event_session_id' => $session->id,
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

        return $this->recordAttendance($request, $attendee, $session, AttendanceMethod::Qr);
    }

    /**
     * Record attendance manually (fallback when QR scanning is unavailable).
     */
    public function manual(ManualAttendanceRequest $request)
    {
        $event = Event::findOrFail($request->validated('event_id'));
        $session = EventSession::where('event_id', $event->id)->findOrFail($request->validated('session_id'));
        $attendee = $this->resolveAttendee($event, $request->validated())->load(['union', 'mission']);

        return $this->recordAttendance($request, $attendee, $session, AttendanceMethod::Manual);
    }

    /**
     * Record a check-out for an existing attendance record (only when the event requires it).
     */
    public function checkOut(Request $request, AttendanceRecord $attendanceRecord)
    {
        $this->authorize('checkOut', $attendanceRecord);

        $event = $attendanceRecord->attendee->event;

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
                $session = EventSession::where('event_id', $event->id)->findOrFail($scan['session_id']);

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
                $attendance = DB::transaction(function () use ($attendee, $session, $method, $request, $scannedAt, $scan) {
                    return $this->recordAttendance(
                        $request,
                        $attendee,
                        $session,
                        $method,
                        $scannedAt,
                        true,
                        $scan['override'] ?? false
                    );
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

    private function recordAttendance(
        Request $request,
        Attendee $attendee,
        EventSession $session,
        AttendanceMethod $method,
        ?Carbon $scannedAt = null,
        bool $isBatchSync = false,
        bool $overrideFlag = false
    ) {
        if ($attendee->first_registered_at === null) {
            throw ValidationException::withMessages(['attendee_id' => 'Registration incomplete.']);
        }

        $existing = AttendanceRecord::where('attendee_id', $attendee->id)
            ->where('event_session_id', $session->id)
            ->first();

        if ($existing !== null && $existing->check_in_at !== null) {
            throw ValidationException::withMessages([
                $method === AttendanceMethod::Qr ? 'qr_token' : 'attendee_id' => 'Already Checked In.',
            ]);
        }

        $canOverride = ($overrideFlag || $request->boolean('override'))
            && ($request->user()->isSuperAdmin() || $request->user()->isOrgAdmin());

        $isEarly = now()->lessThan($session->checkInOpensAt());

        if ($isEarly && ! $canOverride) {
            throw ValidationException::withMessages([
                'session_id' => 'Attendance for this session has not opened yet.',
            ]);
        }

        return DB::transaction(function () use ($existing, $attendee, $session, $method, $request, $isEarly, $scannedAt, $isBatchSync) {
            $attendance = $existing ?? new AttendanceRecord([
                'attendee_id' => $attendee->id,
                'event_session_id' => $session->id,
            ]);

            $attendance->check_in_at = $scannedAt ?? now();
            $attendance->method = $method;
            $attendance->recorded_by = $request->user()->id;
            $attendance->save();

            AuditLog::record(
                $isEarly ? 'attendance.checked_in_override' : 'attendance.checked_in',
                $attendance,
                ['method' => $method->value, 'session_id' => $session->id],
            );

            if ($isBatchSync) {
                return $attendance;
            }

            return AttendanceRecordResource::make($attendance->load(['attendee.union', 'attendee.mission']))
                ->additional($attendee->registration_completed_at === null ? ['incomplete' => true, 'note' => 'Registration incomplete.'] : [])
                ->response()
                ->setStatusCode(201);
        });
    }
}
