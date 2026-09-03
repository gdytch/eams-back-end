<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\EventAttendanceExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventAttendanceSummaryResource;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Organization;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    /**
     * Get attendance summary for a specific event (per-session + overall totals).
     */
    public function eventAttendanceSummary(Event $event)
    {
        $this->authorize('view', $event);

        $summary = $this->buildEventAttendanceSummary($event);

        AuditLog::record('report.event_attendance_summary_viewed', $event);

        return EventAttendanceSummaryResource::make($summary);
    }

    /**
     * Export event attendance report in Excel or PDF format.
     */
    public function exportEventAttendance(Request $request, Event $event)
    {
        $this->authorize('view', $event);

        $format = $request->query('format', 'xlsx');

        AuditLog::record('report.event_attendance_exported', $event, ['format' => $format]);

        if ($format === 'pdf') {
            return $this->exportEventAttendanceAsPdf($event);
        }

        return Excel::download(new EventAttendanceExport($event), "event-{$event->id}-attendance.xlsx");
    }

    /**
     * Get attendance overview for an entire organization (breakdown by union/mission).
     */
    public function organizationAttendanceOverview(Request $request, Organization $organization)
    {
        // Only SuperAdmin or the organization's admin can view organization-wide reports.
        if (! $request->user()->isSuperAdmin() && $request->user()->organization_id !== $organization->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! $request->user()->isSuperAdmin() && ! $request->user()->isOrgAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $events = $organization->events()->with('registrations.attendanceRecords.recordedBy')->get();

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

        foreach ($events as $event) {
            foreach ($event->registrations as $registration) {
                $overview['total_registered']++;

                $checkIn = $registration->attendanceRecords->first();

                if ($checkIn?->check_in_at) {
                    $overview['total_checked_in']++;

                    if ($checkIn?->check_out_at) {
                        $overview['total_checked_out']++;
                    }
                } else {
                    $overview['total_no_show']++;
                }

                $unionName = $registration->attendee->union?->name ?? 'No Union';
                if (! isset($overview['by_union'][$unionName])) {
                    $overview['by_union'][$unionName] = [
                        'registered' => 0,
                        'checked_in' => 0,
                        'checked_out' => 0,
                    ];
                }

                $overview['by_union'][$unionName]['registered']++;
                if ($checkIn?->check_in_at) {
                    $overview['by_union'][$unionName]['checked_in']++;
                    if ($checkIn?->check_out_at) {
                        $overview['by_union'][$unionName]['checked_out']++;
                    }
                }

                $missionName = $registration->attendee->mission?->name ?? 'No Mission';
                if (! isset($overview['by_mission'][$missionName])) {
                    $overview['by_mission'][$missionName] = [
                        'registered' => 0,
                        'checked_in' => 0,
                        'checked_out' => 0,
                    ];
                }

                $overview['by_mission'][$missionName]['registered']++;
                if ($checkIn?->check_in_at) {
                    $overview['by_mission'][$missionName]['checked_in']++;
                    if ($checkIn?->check_out_at) {
                        $overview['by_mission'][$missionName]['checked_out']++;
                    }
                }
            }
        }

        AuditLog::record('report.organization_attendance_overview_viewed', $organization);

        return response()->json($overview);
    }

    private function buildEventAttendanceSummary(Event $event): array
    {
        $sessions = $event->sessions()->with(['attendanceRecords' => fn ($q) => $q->with('eventRegistration')])->get();

        $totalRegistered = $event->registrations()->count();
        $totalCheckedIn = 0;
        $totalCheckedOut = 0;

        $sessionSummaries = [];

        foreach ($sessions as $session) {
            $sessionRegistered = $event->registrations()->count();
            $sessionCheckedIn = $session->attendanceRecords()->whereNotNull('check_in_at')->count();
            $sessionCheckedOut = $session->attendanceRecords()->whereNotNull('check_out_at')->count();

            $totalCheckedIn += $sessionCheckedIn;
            $totalCheckedOut += $sessionCheckedOut;

            $sessionSummaries[] = [
                'session_id' => $session->id,
                'session_name' => $session->name,
                'session_date' => $session->session_date->toDateString(),
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'registered' => $sessionRegistered,
                'checked_in' => $sessionCheckedIn,
                'checked_out' => $sessionCheckedOut,
                'no_show' => $sessionRegistered - $sessionCheckedIn,
            ];
        }

        return [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'total_registered' => $totalRegistered,
            'total_checked_in' => $totalCheckedIn,
            'total_checked_out' => $totalCheckedOut,
            'total_no_show' => ($totalRegistered * $sessions->count()) - $totalCheckedIn,
            'sessions' => $sessionSummaries,
        ];
    }

    private function exportEventAttendanceAsPdf(Event $event)
    {
        $registrations = $event->registrations()->with('attendee.union', 'attendee.mission', 'attendanceRecords')->get();

        $data = $registrations->map(function ($registration) {
            $checkInRecord = $registration->attendanceRecords->first();
            $checkInAt = $checkInRecord?->check_in_at;
            $checkOutAt = $checkInRecord?->check_out_at;
            $method = $checkInRecord?->method?->value ?? 'no_show';
            $status = match (true) {
                $checkOutAt !== null => 'Checked Out',
                $checkInAt !== null => 'Checked In',
                default => 'No Show',
            };

            return [
                'first_name' => $registration->attendee->first_name,
                'last_name' => $registration->attendee->last_name,
                'union' => $registration->attendee->union?->name ?? 'N/A',
                'mission' => $registration->attendee->mission?->name ?? 'N/A',
                'status' => $status,
                'method' => ucfirst($method),
                'check_in_at' => $checkInAt?->format('Y-m-d H:i') ?? '',
                'check_out_at' => $checkOutAt?->format('Y-m-d H:i') ?? '',
            ];
        });

        $pdf = Pdf::loadView('pdf.event-attendance-report', [
            'event' => $event,
            'registrations' => $data,
        ]);

        return $pdf->download("event-{$event->id}-attendance.pdf");
    }

    /**
     * Get organization dashboard: events by status, attendees, registrations, latest event stats, trend, breakdowns.
     */
    public function organizationDashboard(Request $request, Organization $organization)
    {
        if (! $request->user()->isSuperAdmin() && $request->user()->organization_id !== $organization->id) {
            abort(403, 'Unauthorized');
        }

        if (! $request->user()->isSuperAdmin() && ! $request->user()->isOrgAdmin()) {
            abort(403, 'Unauthorized');
        }

        $today = today();
        $events = $organization->events()->get();
        $statusCounts = $events->countBy('status');
        $upcomingCount = $events->filter(fn ($e) => $e->start_date >= $today && $e->status->value !== 'cancelled')->count();
        $ongoingCount = $events->filter(fn ($e) => $e->start_date <= $today && $e->end_date >= $today && $e->status->value !== 'cancelled')->count();

        $dashboard = [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'events' => [
                'total' => $events->count(),
                'draft' => $statusCounts->get('draft', 0),
                'published' => $statusCounts->get('published', 0),
                'completed' => $statusCounts->get('completed', 0),
                'cancelled' => $statusCounts->get('cancelled', 0),
                'upcoming' => $upcomingCount,
                'ongoing' => $ongoingCount,
            ],
            'attendees' => [
                'total' => $organization->attendees()->count(),
            ],
            'registrations' => [
                'total' => $organization->events()->with('registrations')->get()->sum(fn ($e) => $e->registrations->count()),
            ],
            'latest_event' => $this->getLatestEventStats($organization),
            'next_upcoming_event' => $this->getNextUpcomingEvent($organization),
            'attendance_rate_trend' => $this->getAttendanceRateTrend($organization),
            'breakdown_by_union' => $this->getBreakdownByUnion($organization),
            'breakdown_by_mission' => $this->getBreakdownByMission($organization),
        ];

        AuditLog::record('report.organization_dashboard_viewed', $organization);

        return response()->json($dashboard);
    }

    /**
     * Get global dashboard for SuperAdmin: cross-org totals and per-org breakdown.
     */
    public function globalDashboard(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only SuperAdmin can access global dashboard');

        $organizations = Organization::all();
        $allEvents = Event::withoutGlobalScopes()->get();
        $statusCounts = $allEvents->countBy('status');
        $today = today();
        $upcomingCount = $allEvents->filter(fn ($e) => $e->start_date >= $today && $e->status->value !== 'cancelled')->count();
        $ongoingCount = $allEvents->filter(fn ($e) => $e->start_date <= $today && $e->end_date >= $today && $e->status->value !== 'cancelled')->count();

        $byOrganization = $organizations->map(function ($org) {
            $events = $org->events()->count();
            $attendees = $org->attendees()->count();
            $registrations = $org->events()->with('registrations')->get()->sum(fn ($e) => $e->registrations->count());

            return [
                'organization_id' => $org->id,
                'organization_name' => $org->name,
                'total_events' => $events,
                'total_attendees' => $attendees,
                'total_registrations' => $registrations,
            ];
        })->values()->toArray();

        $dashboard = [
            'organizations' => [
                'total' => $organizations->count(),
                'active' => $organizations->where('is_active', true)->count(),
            ],
            'events' => [
                'total' => $allEvents->count(),
                'draft' => $statusCounts->get('draft', 0),
                'published' => $statusCounts->get('published', 0),
                'completed' => $statusCounts->get('completed', 0),
                'cancelled' => $statusCounts->get('cancelled', 0),
                'upcoming' => $upcomingCount,
                'ongoing' => $ongoingCount,
            ],
            'attendees' => [
                'total' => Attendee::withoutGlobalScopes()->count(),
            ],
            'registrations' => [
                'total' => $allEvents->sum(fn ($e) => $e->registrations->count()),
            ],
            'by_organization' => $byOrganization,
        ];

        AuditLog::record('report.global_dashboard_viewed', null);

        return response()->json($dashboard);
    }

    /**
     * Get the latest completed event with attendance stats, or null.
     */
    private function getLatestEventStats(Organization $organization): ?array
    {
        $event = $organization->events()
            ->where('status', 'completed')
            ->orderByDesc('end_date')
            ->first();

        if ($event === null) {
            // Fallback to most recent event by end_date regardless of status.
            $event = $organization->events()
                ->where('end_date', '<', today())
                ->orderByDesc('end_date')
                ->first();
        }

        if ($event === null) {
            return null;
        }

        $totalRegistered = $event->registrations()->count();
        $totalCheckedIn = $event->registrations()
            ->whereExists(function ($q) {
                $q->selectRaw(1)
                    ->from('attendance_records')
                    ->whereColumn('attendance_records.event_registration_id', 'event_registrations.id')
                    ->whereNotNull('check_in_at');
            })->count();
        $totalCheckedOut = $event->registrations()
            ->whereExists(function ($q) {
                $q->selectRaw(1)
                    ->from('attendance_records')
                    ->whereColumn('attendance_records.event_registration_id', 'event_registrations.id')
                    ->whereNotNull('check_out_at');
            })->count();
        $noShow = $totalRegistered - $totalCheckedIn;

        return [
            'id' => $event->id,
            'name' => $event->name,
            'start_date' => $event->start_date->toDateString(),
            'end_date' => $event->end_date->toDateString(),
            'venue' => $event->venue,
            'stats' => [
                'registered' => $totalRegistered,
                'checked_in' => $totalCheckedIn,
                'checked_out' => $totalCheckedOut,
                'no_show' => $noShow,
                'check_in_rate' => $totalRegistered > 0 ? round($totalCheckedIn / $totalRegistered * 100, 1) : 0,
                'check_out_rate' => $totalRegistered > 0 ? round($totalCheckedOut / $totalRegistered * 100, 1) : 0,
            ],
        ];
    }

    /**
     * Get the next upcoming event (earliest start_date >= today), or null.
     */
    private function getNextUpcomingEvent(Organization $organization): ?array
    {
        $event = $organization->events()
            ->where('start_date', '>=', today())
            ->whereIn('status', ['draft', 'published'])
            ->orderBy('start_date')
            ->first();

        if ($event === null) {
            return null;
        }

        return [
            'id' => $event->id,
            'name' => $event->name,
            'start_date' => $event->start_date->toDateString(),
            'end_date' => $event->end_date->toDateString(),
            'venue' => $event->venue,
            'days_until_start' => (int) today()->diffInDays($event->start_date),
            'registrations_count' => $event->registrations()->count(),
        ];
    }

    /**
     * Get attendance rate trend for last 5 completed events.
     */
    private function getAttendanceRateTrend(Organization $organization): array
    {
        $events = $organization->events()
            ->where('status', 'completed')
            ->orderByDesc('end_date')
            ->limit(5)
            ->get();

        return $events->map(function ($event) {
            $totalRegistered = $event->registrations()->count();
            $totalCheckedIn = $event->registrations()
                ->whereExists(function ($q) {
                    $q->selectRaw(1)
                        ->from('attendance_records')
                        ->whereColumn('attendance_records.event_registration_id', 'event_registrations.id')
                        ->whereNotNull('check_in_at');
                })->count();

            return [
                'event_id' => $event->id,
                'event_name' => $event->name,
                'end_date' => $event->end_date->toDateString(),
                'registered' => $totalRegistered,
                'checked_in' => $totalCheckedIn,
                'rate' => $totalRegistered > 0 ? round($totalCheckedIn / $totalRegistered * 100, 1) : 0,
            ];
        })->toArray();
    }

    /**
     * Get registration breakdown by union for the organization.
     */
    private function getBreakdownByUnion(Organization $organization): array
    {
        $breakdown = $organization->events()
            ->with(['registrations.attendee.union'])
            ->get()
            ->flatMap(fn ($e) => $e->registrations)
            ->groupBy(fn ($r) => (string) ($r->attendee->union?->name ?? 'No Union'))
            ->map(fn ($regs) => $regs->count())
            ->toArray();

        return $breakdown;
    }

    /**
     * Get registration breakdown by mission for the organization.
     */
    private function getBreakdownByMission(Organization $organization): array
    {
        $breakdown = $organization->events()
            ->with(['registrations.attendee.mission'])
            ->get()
            ->flatMap(fn ($e) => $e->registrations)
            ->groupBy(fn ($r) => (string) ($r->attendee->mission?->name ?? 'No Mission'))
            ->map(fn ($regs) => $regs->count())
            ->toArray();

        return $breakdown;
    }
}
