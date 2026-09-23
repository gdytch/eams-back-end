<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendeeDashboardResource;
use App\Http\Resources\EventProgramResource;
use App\Http\Resources\EventRegistrationResource;
use App\Models\Attendee;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AttendeeDashboardController extends Controller
{
    /**
     * Get the authenticated attendee's dashboard: profile, account, upcoming/past events, and stats.
     */
    public function index(Request $request)
    {

        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $attendee = Attendee::with(['union', 'mission', 'church'])->where('user_id', $user->id)->first();

        if ($attendee === null) {
            return AttendeeDashboardResource::make([
                'profile' => null,
                'account' => $user,
                'upcoming_events' => [],
                'past_events' => [],
                'next_event' => null,
                'latest_event' => null,
                'stats' => [
                    'total_events_registered' => 0,
                    'total_upcoming_events' => 0,
                    'total_past_events' => 0,
                    'total_sessions_attended' => 0,
                    'total_sessions_available' => 0,
                    'attendance_rate' => 0,
                ],
            ])->response();
        }

        $registrations = $attendee->registrations()
            ->with(['event.sessions', 'attendanceRecords'])
            ->get();

        [$upcomingRegistrations, $pastRegistrations] = $registrations->partition(
            fn ($registration) => $registration->event->end_date->isFuture() || $registration->event->end_date->isToday()
        );

        // Sort upcoming events ascending (soonest first) and past events descending (most recent first).
        $upcomingRegistrations = $upcomingRegistrations->sortBy(fn ($r) => $r->event->start_date);
        $pastRegistrations = $pastRegistrations->sortByDesc(fn ($r) => $r->event->end_date);

        $upcomingEvents = $upcomingRegistrations->map(fn ($registration) => [
            'registration_id' => $registration->id,
            'event' => $registration->event,
            'qr_token' => $registration->qr_token,
            'id_card_ready' => $registration->id_card_generated_at !== null,
            'id_card_url' => "/api/v1/events/{$registration->event->id}/registrations/{$registration->id}/id-card",
            'next_session' => $this->getNextSession($registration->event),
        ]);

        $pastEvents = $pastRegistrations->map(fn ($registration) => [
            'registration_id' => $registration->id,
            'event' => $registration->event,
            'attendance_summary' => [
                'sessions_total' => $registration->event->sessions->count(),
                'sessions_attended' => $registration->attendanceRecords->whereNotNull('check_in_at')->whereIn('event_session_id', $registration->event->sessions->modelKeys())->unique('event_session_id')->count(),
            ],
        ]);

        $totalSessionsAttended = $pastEvents->sum('attendance_summary.sessions_attended');
        $totalSessionsAvailable = $pastRegistrations->sum(fn ($r) => $r->event->sessions->count());
        $attendanceRate = $totalSessionsAvailable > 0 ? round($totalSessionsAttended / $totalSessionsAvailable * 100, 1) : 0;

        $nextEvent = $upcomingEvents->first();
        $latestEvent = $pastEvents->first();

        return AttendeeDashboardResource::make([
            'profile' => $attendee,
            'account' => $user,
            'upcoming_events' => $upcomingEvents->values()->all(),
            'past_events' => $pastEvents->values()->all(),
            'next_event' => $nextEvent,
            'latest_event' => $latestEvent,
            'stats' => [
                'total_events_registered' => $registrations->count(),
                'total_upcoming_events' => $upcomingRegistrations->count(),
                'total_past_events' => $pastRegistrations->count(),
                'total_sessions_attended' => $totalSessionsAttended,
                'total_sessions_available' => $totalSessionsAvailable,
                'attendance_rate' => $attendanceRate,
            ],
        ])->response();
    }

    /**
     * List all registrations for the authenticated attendee.
     */
    public function registrations(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->isAttendee() || $request->user()->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $user = $request->user();
        $attendee = Attendee::where('user_id', $user->id)->firstOrFail();

        $registrations = $attendee->registrations()
            ->with(['event', 'attendanceRecords'])
            ->orderBy('created_at', 'desc')
            ->get();

        return EventRegistrationResource::collection($registrations);
    }

    /**
     * Show the authenticated attendee's session attendance for one registered event.
     */
    public function attendance(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $attendee = Attendee::where('user_id', $user->id)->firstOrFail();
        $registration = $attendee->registrations()
            ->where('event_id', $event->id)
            ->with(['attendanceRecords' => fn ($query) => $query->whereNotNull('check_in_at')])
            ->firstOrFail();

        $sessions = $event->sessions()
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->get();
        $attendanceBySession = $registration->attendanceRecords->keyBy('event_session_id');

        $sessionRows = $sessions->map(function ($session) use ($attendanceBySession) {
            $attendance = $attendanceBySession->get($session->id);

            return [
                'id' => $session->id,
                'name' => $session->name,
                'description' => $session->description,
                'session_date' => $session->session_date?->toDateString(),
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'status' => $attendance === null ? 'absent' : 'present',
                'check_in_at' => $attendance?->check_in_at,
                'check_out_at' => $attendance?->check_out_at,
            ];
        });

        $present = $sessionRows->where('status', 'present')->count();
        $total = $sessionRows->count();

        return response()->json([
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'start_date' => $event->start_date?->toDateString(),
                'end_date' => $event->end_date?->toDateString(),
                'venue' => $event->venue,
            ],
            'registration_id' => $registration->id,
            'stats' => [
                'total_sessions' => $total,
                'sessions_present' => $present,
                'sessions_absent' => $total - $present,
                'attendance_rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0,
            ],
            'sessions' => $sessionRows->values(),
        ]);
    }

    /**
     * Show the program for one event registered to the authenticated attendee.
     */
    public function program(Request $request, Event $event): EventProgramResource
    {
        $user = $request->user();
        abort_unless($user->isAttendee() || $user->has_attendee_account, 403, 'You do not have permission to perform this action.');

        $attendee = Attendee::where('user_id', $user->id)->firstOrFail();
        abort_unless(
            $attendee->registrations()->where('event_id', $event->id)->exists(),
            404,
            'Registration not found.'
        );

        $program = $event->program()->with('days.sections.items')->firstOrFail();

        return EventProgramResource::make($program);
    }

    /**
     * Find the earliest EventSession in the future (or today), or null if none exist.
     */
    private function getNextSession($event): ?array
    {
        $nextSession = $event->sessions
            ->filter(fn ($session) => $session->startsAt()->isFuture() || $session->startsAt()->isToday())
            ->sortBy(fn ($session) => $session->startsAt())
            ->first();

        if ($nextSession === null) {
            return null;
        }

        return [
            'id' => $nextSession->id,
            'event_id' => $nextSession->event_id,
            'name' => $nextSession->name,
            'description' => $nextSession->description,
            'session_date' => $nextSession->session_date?->toDateString(),
            'start_time' => $nextSession->start_time,
            'end_time' => $nextSession->end_time,
            'check_in_opens_at' => $nextSession->checkInOpensAt(),
        ];
    }
}
