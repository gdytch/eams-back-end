<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use Illuminate\Support\Collection;

class EventDashboardReport
{
    public function __construct(private DashboardRegistrationInsights $registrationInsights) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Event $event): array
    {
        $insights = $this->registrationInsights->build(Event::query()->whereKey($event->id));
        $registrations = $this->registrations($event);
        $sessions = $this->sessions($event);
        $organizationRows = collect($insights['registrations_by_organization_level']);
        $attendanceRateTrend = $this->eventOrganizationAttendanceRateTrend($sessions, $registrations, $organizationRows);

        return [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'start_date' => $event->start_date->toDateString(),
                'end_date' => $event->end_date->toDateString(),
                'venue' => $event->venue,
                'status' => $event->status,
            ],
            'attendance' => $this->attendanceSummary($event, $registrations, $sessions),
            'sessions' => $this->sessionSummary($sessions, $registrations->count()),
            'top_check_ins_by_organization' => $this->topCheckInsByOrganization($organizationRows, $attendanceRateTrend),
            'attendance_rate_trend' => $attendanceRateTrend,
            ...$insights,
        ];
    }

    /**
     * @return Collection<int, EventRegistration>
     */
    private function registrations(Event $event): Collection
    {
        return $event->registrations()
            ->with([
                'attendee.union',
                'attendee.mission',
                'attendanceRecords' => fn ($query) => $query
                    ->whereNotNull('check_in_at')
                    ->orderBy('check_in_at'),
            ])
            ->get();
    }

    /**
     * @return Collection<int, EventSession>
     */
    private function sessions(Event $event): Collection
    {
        return $event->sessions()
            ->withCount([
                'attendanceRecords as checked_in_count' => fn ($query) => $query->whereNotNull('check_in_at'),
                'attendanceRecords as checked_out_count' => fn ($query) => $query->whereNotNull('check_out_at'),
            ])
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * @param  Collection<int, EventRegistration>  $registrations
     * @param  Collection<int, EventSession>  $sessions
     * @return array<string, int|float>
     */
    private function attendanceSummary(Event $event, Collection $registrations, Collection $sessions): array
    {
        $registrationCount = $registrations->count();
        $checkedIn = $registrations->filter(fn (EventRegistration $registration) => $registration->attendanceRecords->isNotEmpty())->count();
        $checkedOut = $event->registrations()
            ->whereHas('attendanceRecords', fn ($query) => $query->whereNotNull('check_out_at'))
            ->count();
        $sessionCheckIns = $sessions->sum('checked_in_count');
        $opportunities = $registrationCount * $sessions->count();

        return [
            'registered' => $registrationCount,
            'checked_in' => $checkedIn,
            'checked_out' => $checkedOut,
            'not_checked_in' => $registrationCount - $checkedIn,
            'attendance_rate' => $opportunities > 0 ? round($sessionCheckIns / $opportunities * 100, 1) : 0,
            'check_in_rate' => $registrationCount > 0 ? round($checkedIn / $registrationCount * 100, 1) : 0,
            'check_out_rate' => $registrationCount > 0 ? round($checkedOut / $registrationCount * 100, 1) : 0,
        ];
    }

    /**
     * @param  Collection<int, EventSession>  $sessions
     * @return array<string, mixed>
     */
    private function sessionSummary(Collection $sessions, int $registrationCount): array
    {
        return [
            'total' => $sessions->count(),
            'completed' => $sessions->filter(fn (EventSession $session) => $session->endsAt()->isPast())->count(),
            'upcoming' => $sessions->filter(fn (EventSession $session) => $session->startsAt()->isFuture())->count(),
            'items' => $sessions->map(fn (EventSession $session) => [
                'id' => $session->id,
                'name' => $session->name,
                'session_date' => $session->session_date->toDateString(),
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'checked_in' => $session->checked_in_count,
                'checked_out' => $session->checked_out_count,
                'attendance_rate' => $registrationCount > 0
                    ? round($session->checked_in_count / $registrationCount * 100, 1)
                    : 0,
            ])->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topCheckInsByOrganization(Collection $organizations, array $attendanceRateTrend): array
    {
        $attendanceByOrganization = collect($attendanceRateTrend['series'])->keyBy('key');

        return $organizations
            ->map(fn (array $organization) => [
                ...$organization,
                'check_ins' => $attendanceByOrganization->get($organization['key'])['total_check_ins'] ?? 0,
                'attendance_rate' => $attendanceByOrganization->get($organization['key'])['overall_rate'] ?? 0,
                'unique_attendees' => $organization['checked_in'],
            ])
            ->sortByDesc('attendance_rate')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, EventSession>  $sessions
     * @param  Collection<int, EventRegistration>  $registrations
     * @param  Collection<int, array<string, mixed>>  $organizations
     * @return array<string, mixed>
     */
    private function eventOrganizationAttendanceRateTrend(Collection $sessions, Collection $registrations, Collection $organizations): array
    {
        $labels = $sessions->map(fn (EventSession $session) => [
            'session_id' => $session->id,
            'name' => $session->name,
            'session_date' => $session->session_date->toDateString(),
            'start_time' => $session->start_time,
        ])->all();
        $sessionIndexes = $sessions->mapWithKeys(fn (EventSession $session, int $index) => [$session->id => $index]);
        $series = $this->initializeOrganizationSeries($organizations, $labels);

        foreach ($registrations as $registration) {
            $this->addRegistrationCheckIns($series, $registration, $sessionIndexes);
        }

        return [
            'granularity' => 'session',
            'labels' => $labels,
            'series' => $this->finishOrganizationSeries($series),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $organizations
     * @return Collection<string, array<string, mixed>>
     */
    private function initializeOrganizationSeries(Collection $organizations, array $labels): Collection
    {
        return $organizations->map(fn (array $organization) => [
            'key' => $organization['key'],
            'organization_name' => $organization['organization_name'],
            'organization_level' => $organization['organization_level'],
            'registrations' => $organization['registrations'],
            'overall_rate' => 0,
            'total_check_ins' => 0,
            'checked_in' => array_fill(0, count($labels), 0),
        ])->keyBy('key');
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $series
     * @param  Collection<int, int>  $sessionIndexes
     */
    private function addRegistrationCheckIns(Collection $series, EventRegistration $registration, Collection $sessionIndexes): void
    {
        $level = $registration->attendee?->organization_level?->value ?? 'unassigned';
        $organizationId = match ($level) {
            'union' => $registration->attendee?->union_id,
            'mission' => $registration->attendee?->mission_id,
            default => null,
        };
        $key = $level.':'.($organizationId ?? 'unassigned');

        foreach ($registration->attendanceRecords as $attendanceRecord) {
            $index = $sessionIndexes->get($attendanceRecord->event_session_id);
            if ($index === null || ! $series->has($key)) {
                continue;
            }

            $row = $series->get($key);
            $row['checked_in'][$index]++;
            $row['total_check_ins']++;
            $series->put($key, $row);
        }
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $series
     * @return array<int, array<string, mixed>>
     */
    private function finishOrganizationSeries(Collection $series): array
    {
        return $series->values()->map(function (array $row): array {
            $opportunities = $row['registrations'] * count($row['checked_in']);
            $row['overall_rate'] = $opportunities > 0
                ? round($row['total_check_ins'] / $opportunities * 100, 1)
                : 0;
            $row['data'] = array_map(
                fn (int $checkedIn): float|int => $row['registrations'] > 0
                    ? round($checkedIn / $row['registrations'] * 100, 1)
                    : 0,
                $row['checked_in']
            );

            return $row;
        })->all();
    }
}
