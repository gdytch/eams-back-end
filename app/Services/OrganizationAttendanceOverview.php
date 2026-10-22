<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Organization;

class OrganizationAttendanceOverview
{
    /**
     * @return array<string, mixed>
     */
    public function build(Organization $organization): array
    {
        $events = $organization->events()->get(['events.id']);
        $registrations = Attendee::query()
            ->whereIn('event_id', $events->modelKeys())
            ->orderBy('event_id')
            ->orderBy('id')
            ->with(['attendanceRecords' => fn ($records) => $records->whereNotNull('event_session_id'), 'union', 'mission'])
            ->get();

        $overview = [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'total_events' => $events->count(),
            'total_registered' => 0,
            'total_checked_in' => 0,
            'total_checked_out' => 0,
            'total_no_show' => 0,
            'by_union' => [],
            'by_mission' => [],
        ];

        foreach ($registrations as $registration) {
            $checkIn = $registration->attendanceRecords->first();
            $checkedIn = (bool) $checkIn?->check_in_at;
            $checkedOut = $checkedIn && (bool) $checkIn?->check_out_at;

            $overview['total_registered']++;
            $overview['total_checked_in'] += (int) $checkedIn;
            $overview['total_checked_out'] += (int) $checkedOut;
            $overview['total_no_show'] += (int) ! $checkedIn;

            $this->incrementTerritory($overview['by_union'], $registration->union?->name ?? 'No Union', $checkedIn, $checkedOut);
            $this->incrementTerritory($overview['by_mission'], $registration->mission?->name ?? 'No Mission', $checkedIn, $checkedOut);
        }

        return $overview;
    }

    /**
     * @param  array<string, array{registered: int, checked_in: int, checked_out: int}>  $territories
     */
    private function incrementTerritory(array &$territories, string $name, bool $checkedIn, bool $checkedOut): void
    {
        $territories[$name] ??= ['registered' => 0, 'checked_in' => 0, 'checked_out' => 0];
        $territories[$name]['registered']++;
        $territories[$name]['checked_in'] += (int) $checkedIn;
        $territories[$name]['checked_out'] += (int) $checkedOut;
    }
}
