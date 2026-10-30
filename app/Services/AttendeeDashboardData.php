<?php

namespace App\Services;

use App\Http\Resources\BreakoutResource;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
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
            'has_upcoming_events' => false,
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
        $registrationRows = $registrations->map(fn (Attendee $registration): array => $this->profileRegistration($registration));
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
            'has_upcoming_events' => $upcomingEvents->isNotEmpty(),
            'upcoming_events' => $upcomingEvents->values()->all(),
            'past_events' => $pastEvents->values()->all(),
            'next_event' => $upcomingEvents->first(),
            'latest_event' => $pastEvents->first(),
            'stats' => $this->overviewStats($registrations, $upcomingRegistrations, $pastRegistrations, $pastEvents),
        ];
    }

    /** @return array<string, mixed> */
    public function eventAttendance(Event $event, Attendee $registration, Collection $sessions): array
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
            'breakouts' => $this->breakoutAttendance($event, $registration),
        ];
    }

    /** Status: walk_in, checked_in, missed (ended without a check-in) or pending. */
    private function breakoutStatus(Breakout $breakout, ?AttendanceRecord $record): ?string
    {
        return match (true) {
            ! $breakout->tracksAttendance() => null,
            (bool) $record?->is_walk_in => 'walk_in',
            $record !== null => 'checked_in',
            $breakout->endsAt()->isPast() => 'missed',
            default => 'pending',
        };
    }

    /**
     * The Breakouts an Attendee may see for one event, with own attendance. Own picks and Walk-in Breakouts are
     * always listed; the rest only while the Program is public. Empty without a pick mode or on a draft registration.
     * Never exposes capacity, pick counts or `overbooked`.
     *
     * @return Collection<int, Breakout>
     */
    public function attendeeBreakouts(Event $event, Attendee $registration, bool $programIsPublic): Collection
    {
        if ($event->pick_mode === null || $registration->registration_completed_at === null) {
            return collect();
        }

        $records = $registration->attendanceRecords()
            ->whereNotNull('breakout_id')
            ->whereNotNull('check_in_at')
            ->get()
            ->keyBy('breakout_id');
        $pickedIds = $registration->pickedBreakouts()->withoutGlobalScopes()->pluck('breakouts.id')->map(fn ($id) => (int) $id);
        $ownIds = $pickedIds->merge($records->keys())->unique();

        return $event->breakouts()->withoutGlobalScopes()
            ->when(! $programIsPublic, fn ($query) => $query->whereIn('id', $ownIds))
            ->with(['speakers' => fn ($speakers) => $speakers->withoutGlobalScopes(), 'program.days.sections.items.speaker'])
            ->orderBy('date')->orderBy('start_time')
            ->get()
            ->each(function (Breakout $breakout) use ($pickedIds, $records): void {
                $record = $records->get($breakout->id);
                $picked = $pickedIds->contains($breakout->id);

                $breakout->setAttribute('picked', $picked);
                $breakout->setAttribute('attendance_status', $picked || $record !== null ? $this->breakoutStatus($breakout, $record) : null);
                $breakout->setAttribute('attendance_check_in_at', $breakout->tracksAttendance() ? $record?->check_in_at : null);
            });
    }

    /**
     * Breakout attendance, kept apart from the session rows (ADR 0003). Empty while the event has no pick mode.
     * Status: checked_in, walk_in, missed (ended without a check-in) or pending. Never exposes `overbooked`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function breakoutAttendance(Event $event, Attendee $registration): array
    {
        if ($event->pick_mode === null) {
            return [];
        }

        $records = $registration->attendanceRecords()
            ->whereNotNull('breakout_id')
            ->whereNotNull('check_in_at')
            ->get()
            ->keyBy('breakout_id');

        $ids = $registration->breakouts()->withoutGlobalScopes()->pluck('breakouts.id')->merge($records->keys())->unique();

        return Breakout::withoutGlobalScopes()
            ->trackingAttendance()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Breakout $breakout): string => $breakout->date->toDateString().' '.$breakout->start_time)
            ->values()
            ->map(function (Breakout $breakout) use ($records): array {
                $record = $records->get($breakout->id);

                return [
                    'id' => $breakout->id,
                    'title' => $breakout->title,
                    'date' => $breakout->date->toDateString(),
                    'start_time' => $breakout->start_time,
                    'end_time' => $breakout->end_time,
                    'room' => $breakout->room,
                    'status' => $this->breakoutStatus($breakout, $record),
                    'check_in_at' => $record?->check_in_at,
                    'attendance_record_id' => $record?->id,
                ];
            })
            ->all();
    }

    /**
     * Split registrations into upcoming and past events. Archived events are always past, whatever their dates.
     *
     * @return array{0: Collection<int, Attendee>, 1: Collection<int, Attendee>}
     */
    private function sortedRegistrations(Collection $registrations): array
    {
        [$upcoming, $past] = $registrations->partition(
            fn (Attendee $registration): bool => ! $registration->event->isArchived()
                && ($registration->event->end_date->isFuture() || $registration->event->end_date->isToday())
        );

        return [
            $upcoming->sortBy(fn (Attendee $registration) => $registration->event->start_date),
            $past->sortByDesc(fn (Attendee $registration) => $registration->event->end_date),
        ];
    }

    private function upcomingEvents(Collection $registrations): Collection
    {
        return $registrations->map(fn (Attendee $registration): array => $this->registrationEntry($registration) + [
            'banner' => $this->banner($registration),
            'next_session' => $this->nextSession($registration->event),
            'breakouts' => $this->breakouts($registration->event),
        ]);
    }

    /** Breakouts follow the event program's public setting. */
    private function breakouts(Event $event): ?array
    {
        if (! $event->program?->is_public) {
            return null;
        }

        return BreakoutResource::collection(
            $event->breakouts()->withoutGlobalScopes()->with('speakers')->orderBy('date')->orderBy('start_time')->get()
        )->resolve();
    }

    /**
     * The Attendee's own picks, returned whatever the program's public setting. Null while the event has no pick mode.
     * Never exposes `overbooked`: BreakoutResource only shows it when picks are counted.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function ownPicks(Attendee $registration): ?array
    {
        if ($registration->event?->pick_mode === null) {
            return null;
        }

        $draft = $registration->registration_completed_at === null;

        return $registration->pickedBreakouts
            ->sortBy(fn (Breakout $breakout): string => $breakout->date->toDateString().' '.$breakout->start_time)
            ->values()
            ->map(fn (Breakout $breakout): array => BreakoutResource::make($breakout)->resolve() + ['draft' => $draft])
            ->all();
    }

    /** @return array{status: string, missing: array<int, string>, qr_and_id_card_work: bool}|null */
    private function banner(Attendee $registration): ?array
    {
        $status = $registration->registrationStatus();

        if ($status === null || $status === 'registered') {
            return null;
        }

        return [
            'status' => $status,
            'missing' => app(RegistrationCompleteness::class)->missingLabels($registration),
            'qr_and_id_card_work' => $status === 'incomplete',
        ];
    }

    private function pastEvents(Collection $registrations): Collection
    {
        return $registrations->map(fn (Attendee $registration): array => $this->registrationEntry($registration) + [
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

    /**
     * Fields shared by upcoming and past entries, including what the ID card view and download need.
     *
     * @return array{registration_id: int, event: Event, archived: bool, qr_token: string|null, id_card_ready: bool, picks: array<int, array<string, mixed>>|null, id_card_url: string|null}
     */
    private function registrationEntry(Attendee $registration): array
    {
        return [
            'registration_id' => $registration->id,
            'event' => $registration->event,
            'archived' => $registration->event->isArchived(),
            'qr_token' => $registration->qr_token,
            'id_card_ready' => $registration->id_card_generated_at !== null,
            'picks' => $this->ownPicks($registration),
            'id_card_url' => $registration->first_registered_at === null
                ? null
                : "/api/v1/events/{$registration->event->id}/registrations/{$registration->id}/id-card",
        ];
    }

    private function overviewStats(Collection $registrations, Collection $upcoming, Collection $past, Collection $pastEvents): array
    {
        $totalSessionsAttended = $pastEvents->sum('attendance_summary.sessions_attended');
        $totalSessionsAvailable = $past->sum(fn (Attendee $registration): int => $registration->event->sessions->count());

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

    private function eventAttendanceRows(Attendee $registration, Collection $sessions): Collection
    {
        $attendanceBySession = $registration->attendanceRecords->keyBy('event_session_id');

        return $sessions->map(function (EventSession $session) use ($attendanceBySession, $registration): array {
            $attendance = $attendanceBySession->get($session->id);

            return ['id' => $session->id] + $this->sessionDetails($session) + [
                'status' => $session->attendanceStatus($attendance?->check_in_at !== null, $registration->first_registered_at)?->value,
                'attendance_record_id' => $attendance?->id,
                'check_in_at' => $attendance?->check_in_at,
                'check_out_at' => $attendance?->check_out_at,
            ];
        });
    }

    private function eventAttendanceStats(Collection $sessionRows): array
    {
        $present = $sessionRows->where('status', 'present')->count();
        $absent = $sessionRows->where('status', 'absent')->count();

        return [
            'total_sessions' => $sessionRows->count(),
            'sessions_present' => $present,
            'sessions_absent' => $absent,
            'attendance_rate' => $present + $absent > 0 ? round(($present / ($present + $absent)) * 100, 1) : 0,
        ];
    }

    private function profileRegistration(Attendee $registration): array
    {
        $event = $registration->event;
        $completedSessionIds = $event->sessions
            ->filter(fn (EventSession $session): bool => $session->hasEnded() && $session->isExpected($registration->first_registered_at))
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

    private function profileSessionRows(Attendee $registration): Collection
    {
        $attendanceBySession = $registration->attendanceRecords->keyBy('event_session_id');

        return $registration->event->sessions
            ->sortBy(fn (EventSession $session): string => $session->session_date->toDateString().' '.$session->start_time)
            ->values()
            ->map(function (EventSession $session) use ($attendanceBySession, $registration): array {
                $attendance = $attendanceBySession->get($session->id);

                return ['id' => $session->id] + $this->sessionDetails($session) + [
                    'status' => $session->attendanceStatus($attendance?->check_in_at !== null, $registration->first_registered_at)?->value,
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
