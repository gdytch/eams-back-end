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
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    /**
     * Record attendance from a QR scan.
     */
    public function scan(ScanAttendanceRequest $request)
    {
        $event = Event::findOrFail($request->validated('event_id'));
        $session = EventSession::where('event_id', $event->id)->findOrFail($request->validated('session_id'));

        $registration = EventRegistration::where('event_id', $event->id)
            ->where('qr_token', $request->validated('qr_token'))
            ->first();

        if ($registration === null) {
            throw ValidationException::withMessages([
                'qr_token' => 'This QR code is not registered for this event.',
            ]);
        }

        return $this->recordAttendance($request, $registration, $session, AttendanceMethod::Qr);
    }

    /**
     * Record attendance manually (fallback when QR scanning is unavailable).
     */
    public function manual(ManualAttendanceRequest $request)
    {
        $event = Event::findOrFail($request->validated('event_id'));
        $session = EventSession::where('event_id', $event->id)->findOrFail($request->validated('session_id'));
        $registration = EventRegistration::where('event_id', $event->id)
            ->findOrFail($request->validated('event_registration_id'));

        return $this->recordAttendance($request, $registration, $session, AttendanceMethod::Manual);
    }

    /**
     * Record a check-out for an existing attendance record (only when the event requires it).
     */
    public function checkOut(Request $request, AttendanceRecord $attendanceRecord)
    {
        $this->authorize('view', $attendanceRecord);

        $event = $attendanceRecord->eventRegistration->event;

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
        $this->authorize('view', $session);

        $records = AttendanceRecord::whereHas('eventRegistration', fn ($q) => $q->where('event_id', $event->id))
            ->where('event_session_id', $session->id)
            ->with('eventRegistration.attendee')
            ->paginate($request->input('per_page', 15));

        return AttendanceRecordResource::collection($records);
    }

    /**
     * List every registration for a session with its attendance status (present/absent), sorted by check-in.
     */
    public function roster(Request $request, Event $event, EventSession $session)
    {
        $this->authorize('view', $session);

        $status = strtolower((string) $request->query('status', 'all'));
        $search = $request->query('search', '');

        if (! in_array($status, ['all', 'present', 'absent'], true)) {
            throw ValidationException::withMessages([
                'status' => 'The status must be one of: all, present, absent.',
            ]);
        }

        $checkedInRegistrationIds = AttendanceRecord::where('event_session_id', $session->id)
            ->whereNotNull('check_in_at')
            ->select('event_registration_id');

        $registrations = $event->registrations()
            ->with(['attendee.union', 'attendee.mission'])
            ->addSelect([
                'session_check_in_at' => AttendanceRecord::select('check_in_at')
                    ->whereColumn('event_registration_id', 'event_registrations.id')
                    ->where('event_session_id', $session->id)
                    ->limit(1),
                'session_check_out_at' => AttendanceRecord::select('check_out_at')
                    ->whereColumn('event_registration_id', 'event_registrations.id')
                    ->where('event_session_id', $session->id)
                    ->limit(1),
            ])
            ->withCasts([
                'session_check_in_at' => 'datetime',
                'session_check_out_at' => 'datetime',
            ])
            ->when($status === 'present', fn ($q) => $q->whereIn('id', $checkedInRegistrationIds))
            ->when($status === 'absent', fn ($q) => $q->whereNotIn('id', $checkedInRegistrationIds))
            ->when($search !== '', fn ($q) => $q->whereHas('attendee', function ($query) use ($search) {
                $query->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->orderByRaw('session_check_in_at is null, session_check_in_at asc')
            ->paginate($request->input('per_page', 15));

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

                // Check authorization for this event.
                if (! $request->user()->can('create', [AttendanceRecord::class, $event])) {
                    $result['message'] = 'Unauthorized to record attendance for this event.';
                    $results[] = $result;

                    continue;
                }

                // Resolve the registration.
                $registration = null;

                if (! empty($scan['qr_token'])) {
                    $registration = EventRegistration::where('event_id', $event->id)
                        ->where('qr_token', $scan['qr_token'])
                        ->first();

                    if ($registration === null) {
                        $result['message'] = 'This QR code is not registered for this event.';
                        $results[] = $result;

                        continue;
                    }
                } else {
                    $registration = EventRegistration::where('event_id', $event->id)
                        ->findOrFail($scan['event_registration_id']);
                }

                $method = AttendanceMethod::from($scan['method']);
                $scannedAt = ! empty($scan['scanned_at']) ? Carbon::parse($scan['scanned_at']) : null;

                // Process the attendance in its own transaction.
                $attendance = DB::transaction(function () use ($registration, $session, $method, $request, $scannedAt, $scan) {
                    return $this->recordAttendance(
                        $request,
                        $registration,
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
                $result['message'] = 'Registration not found for this event.';
            } catch (\Exception $e) {
                $result['status'] = 'error';
                $result['message'] = $e->getMessage() ?: 'An unexpected error occurred.';
            }

            $results[] = $result;
        }

        AuditLog::record('attendance.batch_sync', null, ['scan_count' => count($scans)]);

        return response()->json(['results' => $results], 200);
    }

    private function recordAttendance(
        Request $request,
        EventRegistration $registration,
        EventSession $session,
        AttendanceMethod $method,
        ?Carbon $scannedAt = null,
        bool $isBatchSync = false,
        bool $overrideFlag = false
    ) {
        $existing = AttendanceRecord::where('event_registration_id', $registration->id)
            ->where('event_session_id', $session->id)
            ->first();

        if ($existing !== null && $existing->check_in_at !== null) {
            throw ValidationException::withMessages([
                'qr_token' => 'Already Checked In.',
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

        return DB::transaction(function () use ($existing, $registration, $session, $method, $request, $isEarly, $scannedAt, $isBatchSync) {
            $attendance = $existing ?? new AttendanceRecord([
                'event_registration_id' => $registration->id,
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

            return AttendanceRecordResource::make($attendance->load(['eventRegistration.attendee.union', 'eventRegistration.attendee.mission']))
                ->response()
                ->setStatusCode(201);
        });
    }
}
