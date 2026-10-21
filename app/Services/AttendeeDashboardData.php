<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\User;
use Illuminate\Support\Collection;

class AttendeeDashboardData
{
    /** @return array<string, mixed> */
    public function emptyDashboard(User $user): array
    {
        return [
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
        ];
    }

    /** @return array<string, mixed> */
    public function profileDashboard(Attendee $attendee, Collection $registrations): array
    {
        $registrationRows = $registrations->map(fn (EventRegistration $registration): array => $this->profileRegistration($registration));
        $completedSessions = $registrationRows->sum(fn (array $registration): int => $registration['attendance']['completed_sessions']);
        $attendedSessions = $registrationRows->sum(fn (array $registration): int => $registration['attendance']['attended_sessions']);

        return [
            'account' => $this->profileAccount($attendee),
            'registrations' => $registrationRows->all(),
            'stats' => [
                'registration_count' => $registrationRows->count(),
                'completed_sessions' => $completedSessions,
                'attended_sessions' => $attendedSessions,
                'attendance_rate' => $completedSessions > 0
                    ? round($attendedSessions / $completedSessions * 100, 1)
                    : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function attendeeDashboard(User $user, Attendee $attendee, Collection $registrations): array
    {
        [$upcomingRegistrations, $pastRegistrations] = $this->sortedRegistrations($registrations);
        $upcomingEvents = $this->upcomingEvents($upcomingRegistrations);
        $pastEvents = $this->pastEvents($pastRegistrations);

        return [
            'profile' => $attendee,
            'account' => $user,
            'upcoming_events' => $upcomingEvents->values()->all(),
            'past_events' => $pastEvents->values()->all(),
            'next_event' => $upcomingEvents->first(),
            'latest_event' => $pastEvents->first(),
            'stats' => $this->overviewStats($registrations, $upcomingRegistrations, $pastRegistrations, $pastEvents),
        ];
    }

    /** @return array<string, mixed> */
    public function eventAttendance(Event $event, EventRegistration $registration, Collection $sessions): array
    {
        $sessionRows = $this->eventAttendanceRows($registration, $sessions);

        return [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'start_date' => $event->start_date?->toDateString(),
                'end_date' => $event->end_date?->toDateString(),
                'venue' => $event->venue,
            ],
            'registration_id' => $registration->id,
            'stats' => $this->eventAttendanceStats($sessionRows),
            'sessions' => $sessionRows->values(),
        ];
    }

    private function sortedRegistrations(Collection $registrations): array
    {
        [$upcoming, $past] = $registrations->partition(
            fn (EventRegistration $registration): bool => $registration->event->end_date->isFuture()
                || $registration->event->end_date->isToday()
        );

        return [
            $upcoming->sortBy(fn (EventRegistration $registration) => $registration->event->start_date),
            $past->sortByDesc(fn (EventRegistration $registration) => $registration->event->end_date),
        ];
    }

    private function upcomingEvents(Collection $registrations): Collection
    {
        return $registrations->map(fn (EventRegistration $registration): array => [
            'registration_id' => $registration->id,
            'event' => $registration->event,
            'qr_token' => $registration->qr_token,
            'id_card_ready' => $registration->id_card_generated_at !== null,
            'id_card_url' => "/api/v1/events/{$registration->event->id}/registrations/{$registration->id}/id-card",
            'next_session' => $this->nextSession($registration->event),
        ]);
    }

    private function pastEvents(Collection $registrations): Collection
    {
        return $registrations->map(fn (EventRegistration $registration): array => [
            'registration_id' => $registration->id,
            'event' => $registration->event,
            'attendance_summary' => [
                'sessions_total' => $registration->event->sessions->count(),
                'sessions_attended' => $registration->attendanceRecords
                    ->whereNotNull('check_in_at')
                    ->whereIn('event_session_id', $registration->event->sessions->modelKeys())
                    ->unique('event_session_id')
                    ->count(),
            ],
        ]);
    }

    private function overviewStats(Collection $registrations, Collection $upcoming, Collection $past, Collection $pastEvents): array
    {
        $totalSessionsAttended = $pastEvents->sum('attendance_summary.sessions_attended');
        $totalSessionsAvailable = $past->sum(fn (EventRegistration $registration): int => $registration->event->sessions->count());

        return [
            'total_events_registered' => $registrations->count(),
            'total_upcoming_events' => $upcoming->count(),
            'total_past_events' => $past->count(),
            'total_sessions_attended' => $totalSessionsAttended,
            'total_sessions_available' => $totalSessionsAvailable,
            'attendance_rate' => $totalSessionsAvailable > 0
                ? round($totalSessionsAttended / $totalSessionsAvailable * 100, 1)
                : 0,
        ];
    }

    private function profileAccount(Attendee $attendee): array
    {
        return [
            'linked' => $attendee->user !== null,
            'email' => $attendee->user?->email,
            'email_verified' => $attendee->user?->hasVerifiedEmail(),
            'invite_status' => ($attendee->user?->invite_token ?? $attendee->invite_token) !== null
                ? 'pending'
                : (($attendee->user?->invited_at ?? $attendee->invited_at) !== null ? 'accepted' : null),
        ];
    }

    private function eventAttendanceRows(EventRegistration $registration, Collection $sessions): Collection
    {
        $attendanceBySession = $registration->attendanceRecords->keyBy('event_session_id');

        return $sessions->map(function (EventSession $session) use ($attendanceBySession): array {
            $attendance = $attendanceBySession->get($session->id);

            return ['id' => $session->id] + $this->sessionDetails($session) + [
                'status' => $attendance === null ? 'absent' : 'present',
                'attendance_record_id' => $attendance?->id,
                'check_in_at' => $attendance?->check_in_at,
                'check_out_at' => $attendance?->check_out_at,
            ];
        });
    }

    private function eventAttendanceStats(Collection $sessionRows): array
    {
        $present = $sessionRows->where('status', 'present')->count();
        $total = $sessionRows->count();

        return [
            'total_sessions' => $total,
            'sessions_present' => $present,
            'sessions_absent' => $total - $present,
            'attendance_rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0,
        ];
    }

    private function profileRegistration(EventRegistration $registration): array
    {
        $event = $registration->event;
        $completedSessionIds = $event->sessions
            ->filter(fn (EventSession $session): bool => $session->endsAt()->isPast())
            ->pluck('id');
        $attendedSessions = $registration->attendanceRecords
            ->whereNotNull('check_in_at')
            ->pluck('event_session_id')
            ->unique()
            ->intersect($completedSessionIds)
            ->count();
        $completedSessions = $completedSessionIds->count();
        $sessionRows = $this->profileSessionRows($registration);

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
    }

    private function profileSessionRows(EventRegistration $registration): Collection
    {
        $attendanceBySession = $registration->attendanceRecords->keyBy('event_session_id');

        return $registration->event->sessions
            ->sortBy(fn (EventSession $session): string => $session->session_date->toDateString().' '.$session->start_time)
            ->values()
            ->map(function (EventSession $session) use ($attendanceBySession): array {
                $attendance = $attendanceBySession->get($session->id);

                return ['id' => $session->id] + $this->sessionDetails($session) + [
                    'status' => $attendance?->check_in_at !== null
                        ? 'present'
                        : ($session->endsAt()->isPast() ? 'absent' : 'upcoming'),
                    'check_in_at' => $attendance?->check_in_at,
                    'check_out_at' => $attendance?->check_out_at,
                    'method' => $attendance?->method,
                ];
            });
    }

    private function nextSession(Event $event): ?array
    {
        $nextSession = $event->sessions
            ->filter(fn (EventSession $session): bool => $session->startsAt()->isFuture() || $session->startsAt()->isToday())
            ->sortBy(fn (EventSession $session) => $session->startsAt())
            ->first();

        if ($nextSession === null) {
            return null;
        }

        return ['id' => $nextSession->id, 'event_id' => $nextSession->event_id]
            + $this->sessionDetails($nextSession)
            + ['check_in_opens_at' => $nextSession->checkInOpensAt()];
    }

    private function sessionDetails(EventSession $session): array
    {
        return [
            'name' => $session->name,
            'description' => $session->description,
            'session_date' => $session->session_date?->toDateString(),
            'start_time' => $session->start_time,
            'end_time' => $session->end_time,
        ];
    }
}
