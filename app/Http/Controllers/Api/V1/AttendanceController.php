<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\ManualAttendanceRequest;
use App\Http\Requests\ScanAttendanceRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use Illuminate\Http\Request;
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
    public function forSession(Event $event, EventSession $session)
    {
        $this->authorize('view', $session);

        $records = AttendanceRecord::whereHas('eventRegistration', fn($q) => $q->where('event_id', $event->id))
            ->where('event_session_id', $session->id)
            ->with('eventRegistration.attendee')
            ->get();

        return AttendanceRecordResource::collection($records);
    }

    private function recordAttendance(Request $request, EventRegistration $registration, EventSession $session, AttendanceMethod $method)
    {
        $existing = AttendanceRecord::where('event_registration_id', $registration->id)
            ->where('event_session_id', $session->id)
            ->first();

        if ($existing !== null && $existing->check_in_at !== null) {
            throw ValidationException::withMessages([
                'qr_token' => 'Already Checked In.',
            ]);
        }

        $canOverride = $request->boolean('override')
            && ($request->user()->isSuperAdmin() || $request->user()->isOrgAdmin());

        $isEarly = now()->lessThan($session->checkInOpensAt());

        if ($isEarly && ! $canOverride) {
            throw ValidationException::withMessages([
                'session_id' => 'Attendance for this session has not opened yet.',
            ]);
        }

        return DB::transaction(function () use ($existing, $registration, $session, $method, $request, $isEarly) {
            $attendance = $existing ?? new AttendanceRecord([
                'event_registration_id' => $registration->id,
                'event_session_id' => $session->id,
            ]);

            $attendance->check_in_at = now();
            $attendance->method = $method;
            $attendance->recorded_by = $request->user()->id;
            $attendance->save();

            AuditLog::record(
                $isEarly ? 'attendance.checked_in_override' : 'attendance.checked_in',
                $attendance,
                ['method' => $method->value, 'session_id' => $session->id],
            );

            return AttendanceRecordResource::make($attendance->load(['eventRegistration.attendee.union', 'eventRegistration.attendee.mission']))
                ->response()
                ->setStatusCode(201);
        });
    }
}
