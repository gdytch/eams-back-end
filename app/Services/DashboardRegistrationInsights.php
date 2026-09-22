<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Database\Eloquent\Builder;

class DashboardRegistrationInsights
{
    /**
     * Each registration belongs to exactly one territory, selected by organization_level.
     * Check-ins count registrations with at least one check-in, never session scans.
     *
     * @param  Builder<Event>  $events
     * @return array<string, mixed>
     */
    public function build(Builder $events): array
    {
        $registrations = EventRegistration::query()
            ->whereIn('event_id', (clone $events)->select('events.id'));

        $territories = (clone $registrations)
            ->leftJoin('attendees', 'attendees.id', '=', 'event_registrations.attendee_id')
            ->leftJoin('unions', 'unions.id', '=', 'attendees.union_id')
            ->leftJoin('missions', 'missions.id', '=', 'attendees.mission_id')
            ->selectRaw("COALESCE(attendees.organization_level, 'unassigned') as organization_level")
            ->selectRaw("CASE attendees.organization_level WHEN 'union' THEN unions.id WHEN 'mission' THEN missions.id END as territory_id")
            ->selectRaw("CASE attendees.organization_level WHEN 'union' THEN unions.name WHEN 'mission' THEN missions.name END as organization_name")
            ->selectRaw('COUNT(*) as registrations')
            ->selectRaw('SUM(CASE WHEN EXISTS (SELECT 1 FROM attendance_records WHERE attendance_records.event_registration_id = event_registrations.id AND check_in_at IS NOT NULL) THEN 1 ELSE 0 END) as checked_in')
            ->groupBy('attendees.organization_level', 'territory_id', 'organization_name')
            ->orderByDesc('registrations')
            ->orderBy('organization_name')
            ->toBase()->get();

        $total = (int) $territories->sum('registrations');
        $checkedIn = (int) $territories->sum('checked_in');
        $dailyCounts = (clone $registrations)
            ->whereBetween('registered_at', [today()->subDays(13), now()])
            ->selectRaw('DATE(registered_at) as date, COUNT(*) as total')
            ->groupByRaw('DATE(registered_at)')->pluck('total', 'date');

        return [
            'registration_summary' => [
                'total' => $total,
                'checked_in' => $checkedIn,
                'not_checked_in' => $total - $checkedIn,
                'check_in_rate' => $total > 0 ? round($checkedIn / $total * 100, 1) : 0,
                'last_14_days' => (int) $dailyCounts->sum(),
            ],
            'registrations_by_organization_level' => $territories->map(fn (object $row): array => [
                'key' => $row->organization_level.':'.($row->territory_id ?? 'unassigned'),
                'organization_level' => $row->organization_level,
                'organization_id' => $row->territory_id,
                'organization_name' => $row->organization_name ?? 'Unassigned',
                'registrations' => (int) $row->registrations,
                'checked_in' => (int) $row->checked_in,
                'share' => $total > 0 ? round($row->registrations / $total * 100, 1) : 0,
                'check_in_rate' => $row->registrations > 0 ? round($row->checked_in / $row->registrations * 100, 1) : 0,
            ])->all(),
            'registration_trend' => collect(range(13, 0))->map(fn (int $days): array => [
                'date' => today()->subDays($days)->toDateString(),
                'registrations' => (int) $dailyCounts->get(today()->subDays($days)->toDateString(), 0),
            ])->all(),
        ];
    }
}
