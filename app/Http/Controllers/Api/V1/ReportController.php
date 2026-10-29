<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Exports\AttendeeSummaryExport;
use App\Exports\EventAttendanceExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventAttendanceSummaryResource;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Services\BreakoutAttendanceReport;
use App\Services\DashboardRegistrationInsights;
use App\Services\EventDashboardReport;
use App\Services\OrganizationAttendanceOverview;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(
        private BreakoutAttendanceReport $breakoutReport,
        private DashboardRegistrationInsights $registrationInsights,
        private EventDashboardReport $eventDashboardReport,
        private OrganizationAttendanceOverview $attendanceOverview,
    ) {}

    public function eventSessionQuickStats(Event $event, EventSession $session)
    {

        $this->authorize('viewRoster', $session);

        $counts = $session->attendanceCounts($event);

        return response()->json([
            'event_id' => $event->id,
            'session_id' => $session->id,
            'total_registered' => $event->attendees()->expectedAt($session)->count(),
            'attendance' => [
                'present' => $counts['present'],
                'absent' => $counts['absent'],
                'upcoming' => $counts['upcoming'],
                'attendance_rate' => $counts['rate'],
            ],
        ]);
    }

    /**
     * Get a dashboard scoped to one event, including per-organization check-in performance.
     */
    public function eventDashboard(Event $event)
    {
        $this->authorize('viewStaffData', $event);

        $dashboard = $this->eventDashboardReport->build($event);

        AuditLog::record('report.event_dashboard_viewed', $event);

        return response()->json($dashboard);
    }

    /**
     * Get attendance summary for a specific event (per-session + overall totals).
     * Also includes a paginated list of attendance records for display in a table.
     */
    public function eventAttendanceSummary(Request $request, Event $event)
    {
        $this->authorize('viewStaffData', $event);

        $summary = $this->buildEventAttendanceSummary($event);

        // Fetch paginated attendance records with related data
        $perPage = $request->input('per_page', 10);
        $attendanceRecords = $event->attendees()
            ->whereNotNull('first_registered_at')
            ->with([
                'attendanceRecords' => fn ($records) => $records->counted(),
                'attendanceRecords.eventSession',
                'attendanceRecords.breakout',
                'attendanceRecords.viaBreakout',
                'attendanceRecords.recordedBy',
                'union',
                'mission',
            ])
            ->paginate($perPage);

        // Transform registrations into attendance records for table display
        $records = collect();
        foreach ($attendanceRecords->items() as $registration) {
            foreach ($registration->attendanceRecords as $attendanceRecord) {
                $records->push([
                    'id' => $attendanceRecord->id,
                    'attendee_name' => $registration->first_name.' '.$registration->last_name,
                    'attendee_id' => $registration->id,
                    'email' => $registration->email ?? 'N/A',
                    'union' => $registration->union?->name ?? 'N/A',
                    'mission' => $registration->mission?->name ?? 'N/A',
                    'session' => $attendanceRecord->eventSession?->name ?? 'N/A',
                    'session_date' => $attendanceRecord->eventSession?->session_date?->toDateString() ?? 'N/A',
                    'breakout' => $attendanceRecord->breakout?->title,
                    'via_breakout' => $attendanceRecord->viaBreakout?->title,
                    'is_walk_in' => $attendanceRecord->is_walk_in,
                    'check_in_at' => $attendanceRecord->check_in_at?->format('Y-m-d H:i:s'),
                    'check_out_at' => $attendanceRecord->check_out_at?->format('Y-m-d H:i:s'),
                    'method' => $attendanceRecord->method?->value ?? 'manual',
                    'recorded_by' => $attendanceRecord->recordedBy?->name ?? 'System',
                    'status' => match (true) {
                        $attendanceRecord->check_out_at !== null => 'Checked Out',
                        $attendanceRecord->check_in_at !== null => 'Checked In',
                        default => 'Not checked in',
                    },
                ]);
            }
        }

        $from = (($attendanceRecords->currentPage() - 1) * $attendanceRecords->perPage()) + 1;
        $to = $from + count($attendanceRecords->items()) - 1;

        $summary['attendance_records'] = [
            'data' => $records->values()->toArray(),
            'pagination' => [
                'total' => $attendanceRecords->total(),
                'per_page' => $attendanceRecords->perPage(),
                'current_page' => $attendanceRecords->currentPage(),
                'last_page' => $attendanceRecords->lastPage(),
                'from' => $from,
                'to' => $to,
            ],
        ];

        AuditLog::record('report.event_attendance_summary_viewed', $event);

        return EventAttendanceSummaryResource::make($summary);
    }

    /**
     * Every breakout with capacity, picks and attendance counts.
     */
    public function breakoutReport(Event $event)
    {
        $this->authorize('viewStaffData', $event);

        AuditLog::record('report.breakout_attendance_viewed', $event);

        return response()->json(['data' => $this->breakoutReport->overview($event)->values()]);
    }

    /**
     * Drill-down roster of one breakout: Expected pick holders plus walk-ins.
     */
    public function breakoutRoster(Event $event, Breakout $breakout)
    {
        $this->authorize('viewStaffData', $event);

        AuditLog::record('report.breakout_roster_viewed', $event, ['breakout_id' => $breakout->id]);

        return response()->json(['data' => $this->breakoutReport->roster($event, $breakout)->values()]);
    }

    /**
     * Export event attendance report in Excel or PDF format.
     * Optional session_id query parameter to export only a specific session's attendance.
     */
    public function exportEventAttendance(Request $request, Event $event)
    {
        $this->authorize('viewStaffData', $event);

        $format = $request->query('format', 'xlsx');
        $sessionId = $request->query('session_id');
        $breakoutId = $request->query('breakout_id');

        $session = $sessionId ? $event->sessions()->findOrFail($sessionId) : null;
        $breakout = $breakoutId && ! $session ? $event->breakouts()->whereKey($breakoutId)->firstOrFail() : null;

        AuditLog::record('report.event_attendance_exported', $event, ['format' => $format, 'session_id' => $sessionId]);

        // Session-only exports hold no breakout data; every other export of an event with breakouts does.
        if (! $session && $event->breakouts()->exists()) {
            AuditLog::record('breakout_attendance.exported', $event, ['format' => $format, 'breakout_id' => $breakout?->id]);
        }

        if ($format === 'pdf') {
            return $this->exportEventAttendanceAsPdf($event, $session, $breakout);
        }

        $filename = match (true) {
            $session !== null => "event-{$event->id}-session-{$session->id}-attendance.xlsx",
            $breakout !== null => "event-{$event->id}-breakout-{$breakout->id}-attendance.xlsx",
            default => "event-{$event->id}-attendance.xlsx",
        };

        return Excel::download(new EventAttendanceExport($event, $session, $breakout), $filename);
    }

    /**
     * Per-attendee attendance across all sessions of the event, sorted by organization level then name.
     */
    public function attendeeSummary(Event $event)
    {
        $this->authorize('viewStaffData', $event);

        AuditLog::record('report.attendee_summary_viewed', $event);

        return response()->json(['data' => $this->buildAttendeeSummary($event)->values()]);
    }

    public function exportAttendeeSummary(Event $event)
    {
        $this->authorize('viewStaffData', $event);

        AuditLog::record('report.attendee_summary_exported', $event);

        return Excel::download(
            new AttendeeSummaryExport($this->buildAttendeeSummary($event), $event->breakouts()->exists()),
            "event-{$event->id}-attendance-summary.xlsx",
        );
    }

    private function buildAttendeeSummary(Event $event)
    {
        $sessions = $event->sessions()->orderBy('session_date')->orderBy('start_time')->get();
        $breakouts = $event->breakouts()->get();
        $levelOrder = ['union' => 0, 'mission' => 1];

        return $event->attendees()
            ->whereNotNull('first_registered_at')
            ->with(['union', 'mission', 'attendanceRecords' => fn ($q) => $q->whereNotNull('check_in_at')])
            ->when($breakouts->isNotEmpty(), fn ($q) => $q->with('breakouts:breakouts.id'))
            ->get()
            ->map(function ($registration) use ($sessions, $breakouts) {
                $attendee = $registration;
                $presentIds = $registration->attendanceRecords->pluck('event_session_id')->filter()->unique();
                $breakoutPresentIds = $registration->attendanceRecords->pluck('breakout_id')->filter()->unique();
                $present = $presentIds->count();
                $status = fn ($session) => $session->attendanceStatus($presentIds->contains($session->id), $registration->first_registered_at);
                $absent = $sessions->filter(fn ($s) => $status($s) === AttendanceStatus::Absent);
                $upcoming = $sessions->filter(fn ($s) => $status($s) === AttendanceStatus::Upcoming);
                $presentEnded = $sessions->filter(fn ($s) => $presentIds->contains($s->id) && $s->hasEnded() && $s->isExpected($registration->first_registered_at))->count();

                return [
                    'event_registration_id' => $registration->id,
                    'attendee_id' => $attendee->id,
                    'attendee_name' => $attendee->full_name,
                    'photo_urls' => $attendee->photo_urls,
                    'last_name' => $attendee->last_name,
                    'first_name' => $attendee->first_name,
                    'organization_level' => $attendee->organization_level?->value,
                    'organization_name' => $attendee->organization_level_name,
                    'union' => $attendee->union,
                    'mission' => $attendee->mission,
                    'present' => $present,
                    'present_sessions' => $sessions->whereIn('id', $presentIds)->pluck('name')->values(),
                    'absent_sessions' => $absent->pluck('name')->values(),
                    'remaining_sessions' => $upcoming->pluck('name')->values(),
                    'absent' => $absent->count(),
                    'remaining' => $upcoming->count(),
                    // Breakouts stay out of the sessions-only rating; Absent counts held picks that ended unattended.
                    'breakout_present' => $breakoutPresentIds->count(),
                    'breakout_absent' => $breakouts->filter(fn ($b) => $registration->breakouts->contains('id', $b->id)
                        && $b->attendanceStatus($breakoutPresentIds->contains($b->id), $registration->first_registered_at) === AttendanceStatus::Absent)->count(),
                    'attendance_rating' => $presentEnded + $absent->count() > 0
                        ? round($presentEnded / ($presentEnded + $absent->count()) * 100, 1)
                        : 0,
                ];
            })
            ->reject(fn ($row) => $row['present'] + $row['absent'] + $row['remaining'] + $row['breakout_present'] + $row['breakout_absent'] === 0)
            ->sort(fn ($a, $b) => $this->summarySortKey($a, $levelOrder) <=> $this->summarySortKey($b, $levelOrder));
    }

    private function summarySortKey(array $row, array $levelOrder): array
    {
        return [
            $levelOrder[$row['organization_level']] ?? 2,
            strtolower($row['organization_name'] ?? ''),
            strtolower($row['last_name']),
            strtolower($row['first_name']),
        ];
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

        $overview = $this->attendanceOverview->build($organization);

        AuditLog::record('report.organization_attendance_overview_viewed', $organization);

        return response()->json($overview);
    }

    private function buildEventAttendanceSummary(Event $event): array
    {
        $sessions = $event->sessions()->with(['attendanceRecords' => fn ($q) => $q->with('attendee')])->get();

        $totalRegistered = $event->attendees()->whereNotNull('first_registered_at')->count();
        $totalCheckedIn = 0;
        $totalCheckedOut = 0;
        $totalAbsent = 0;

        $sessionSummaries = [];

        foreach ($sessions as $session) {
            $counts = $session->attendanceCounts($event);
            $sessionCheckedIn = $counts['present'];
            $sessionCheckedOut = $session->attendanceRecords()->whereNotNull('check_out_at')->count();

            $totalCheckedIn += $sessionCheckedIn;
            $totalCheckedOut += $sessionCheckedOut;
            $totalAbsent += $counts['absent'];

            $sessionSummaries[] = [
                'session_id' => $session->id,
                'session_name' => $session->name,
                'session_date' => $session->session_date->toDateString(),
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'registered' => $event->attendees()->expectedAt($session)->count(),
                'checked_in' => $sessionCheckedIn,
                'checked_out' => $sessionCheckedOut,
                'no_show' => $counts['absent'],
                'upcoming' => $counts['upcoming'],
                'attendance_rate' => $counts['rate'],
            ];
        }

        return [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'total_registered' => $totalRegistered,
            'total_checked_in' => $totalCheckedIn,
            'total_checked_out' => $totalCheckedOut,
            'total_no_show' => $totalAbsent,
            'sessions' => $sessionSummaries,
        ];
    }

    private function exportEventAttendanceAsPdf(Event $event, ?EventSession $session = null, ?Breakout $breakout = null)
    {
        if ($breakout) {
            $sessions = (new EventSession)->newCollection();
            $filename = "event-{$event->id}-breakout-{$breakout->id}-attendance.pdf";
            $includeDetailedRoster = true;
        } elseif ($session) {
            // Export only the specific session - include detailed roster
            $sessions = (new EventSession)->newCollection([$session]);
            $filename = "event-{$event->id}-session-{$session->id}-attendance.pdf";
            $includeDetailedRoster = true;
        } else {
            // Export all sessions - summary only to avoid memory exhaustion
            $sessions = $event->sessions()->orderBy('session_date')->get();
            $filename = "event-{$event->id}-attendance.pdf";
            $includeDetailedRoster = false;
        }

        $sessions->loadCount([
            'attendanceRecords as checked_in_count' => fn ($query) => $query->whereNotNull('check_in_at'),
            'attendanceRecords as checked_out_count' => fn ($query) => $query->whereNotNull('check_out_at'),
        ]);

        // Prepare per-session summaries
        $sessionSummaries = [];
        $sessionDetailedRosters = [];

        foreach ($sessions as $sess) {
            $counts = $sess->attendanceCounts($event);

            $sessionSummaries[] = [
                'name' => $sess->name,
                'date' => $sess->session_date->format('M d, Y'),
                'total_registered' => $event->attendees()->expectedAt($sess)->count(),
                'checked_in' => $counts['present'],
                'checked_out' => $sess->checked_out_count,
                'no_show' => $counts['absent'],
                'check_in_rate' => $counts['rate'],
            ];

            // Only prepare detailed roster when viewing a single session
            if ($includeDetailedRoster) {
                $sessionRoster = $event->attendees()->inAttendanceOf($sess)->with('union', 'mission', 'attendanceRecords.viaBreakout')->get()
                    ->map(fn ($reg) => $this->mapRegistrationToRow($reg, $sess, $reg->attendanceRecords->firstWhere('event_session_id', $sess->id)));

                $sessionDetailedRosters[] = [
                    'name' => $sess->name,
                    'date' => $sess->session_date->format('M d, Y'),
                    'registrations' => $sessionRoster->toArray(),
                ];
            }
        }

        // Breakouts follow the same detail rule as sessions: a roster only for a single export.
        $breakoutSummaries = $session ? collect() : $this->breakoutReport->overview($event)
            ->when($breakout, fn ($rows) => $rows->where('breakout_id', $breakout->id));
        $breakoutRosters = $includeDetailedRoster
            ? $event->breakouts()->whereIn('id', $breakoutSummaries->pluck('breakout_id'))->orderBy('date')->orderBy('start_time')->orderBy('id')->get()
                ->map(fn (Breakout $b) => [
                    'title' => $b->title,
                    'date' => $b->date->toDateString(),
                    'rows' => $this->breakoutReport->roster($event, $b)->all(),
                ])->all()
            : [];

        $pdf = Pdf::loadView('pdf.event-attendance-report', [
            'breakoutSummaries' => $breakoutSummaries->values()->all(),
            'breakoutRosters' => $breakoutRosters,
            'event' => $event,
            'sessionSummaries' => $sessionSummaries,
            'sessionDetailedRosters' => $sessionDetailedRosters,
            'includeDetailedRoster' => $includeDetailedRoster,
        ]);

        return $pdf->download($filename);
    }

    private function mapRegistrationToRow($registration, EventSession $session, $attendanceRecord): array
    {
        $checkInAt = $attendanceRecord?->check_in_at;
        $checkOutAt = $attendanceRecord?->check_out_at;
        $method = $attendanceRecord?->method?->value ?? 'no_show';
        $status = match (true) {
            $checkOutAt !== null => 'Checked Out',
            $checkInAt !== null => 'Checked In',
            default => $session->attendanceStatus(false, $registration->first_registered_at)?->label(),
        };

        return [
            'via_breakout' => $attendanceRecord?->viaBreakout?->title ?? '',
            'first_name' => $registration->first_name,
            'last_name' => $registration->last_name,
            'union' => $registration->union?->code ?? 'N/A',
            'mission' => $registration->mission?->code ?? 'N/A',
            'status' => $status,
            'method' => ucfirst($method),
            'check_in_at' => $checkInAt?->format('Y-m-d H:i') ?? '',
            'check_out_at' => $checkOutAt?->format('Y-m-d H:i') ?? '',
        ];
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
                'total' => $organization->events()->with('attendees')->get()->sum(fn ($e) => $e->attendees->count()),
            ],
            'latest_event' => $this->getLatestEventStats($organization),
            'next_upcoming_event' => $this->getNextUpcomingEvent($organization),
            'attendance_rate_trend' => $this->getAttendanceRateTrend($organization),
            ...$this->registrationInsights->build(Event::query()->where('organization_id', $organization->id)),
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
            $registrations = $org->events()->with('attendees')->get()->sum(fn ($e) => $e->attendees->count());

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
                'total' => $allEvents->sum(fn ($e) => $e->attendees->count()),
            ],
            'by_organization' => $byOrganization,
            ...$this->registrationInsights->build(Event::query()),
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

        $totalRegistered = $event->attendees()->count();
        $totalCheckedIn = $event->attendees()
            ->whereHas('attendanceRecords', fn ($records) => $records->counted()->whereNotNull('check_in_at'))
            ->count();
        $totalCheckedOut = $event->attendees()
            ->whereHas('attendanceRecords', fn ($records) => $records->counted()->whereNotNull('check_out_at'))
            ->count();
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
            'registrations_count' => $event->attendees()->count(),
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
            $totalRegistered = $event->attendees()->count();
            $totalCheckedIn = $event->attendees()
                ->whereHas('attendanceRecords', fn ($records) => $records->counted()->whereNotNull('check_in_at'))
                ->count();

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
}
