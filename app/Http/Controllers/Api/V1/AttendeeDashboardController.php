<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendeeDashboardResource;
use App\Models\Attendee;
use Illuminate\Http\Request;

class AttendeeDashboardController extends Controller
{
    /**
     * Get the authenticated attendee's dashboard: profile, account, upcoming/past events, and stats.
     */
    public function index(Request $request)
    {
        abort_unless($request->user()->isAttendee(), 403, 'You do not have permission to perform this action.');

        $user = $request->user();
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
                'sessions_attended' => $registration->attendanceRecords->count(),
            ],
        ]);

        $totalSessionsAttended = $registrations->sum(fn ($r) => $r->attendanceRecords->count());
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
