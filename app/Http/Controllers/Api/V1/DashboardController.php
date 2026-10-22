<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use App\Services\DashboardRegistrationInsights;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class DashboardController extends Controller
{
    public function __construct(private DashboardRegistrationInsights $registrationInsights) {}

    /**
     * Get the authenticated staff user's home dashboard, tailored to their role
     * (SuperAdmin: cross-organization overview, OrgAdmin: organization overview,
     * Checker: operational/scanning overview).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        abort_if($user->isAttendee(), 403, 'Attendee accounts should use the attendee dashboard endpoint.');

        $data = match (true) {
            $user->isSuperAdmin() => $this->superAdminDashboard(),
            $user->isOrgAdmin() => $this->orgAdminDashboard($user),
            default => $this->checkerDashboard($user),
        };

        $data = ['role' => $user->role, 'user' => $this->userSummary($user), 'generated_at' => now()] + $data;

        AuditLog::record('dashboard.viewed', $user->organization);

        return response()->json($data);
    }

    private function userSummary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'organization_id' => $user->organization_id,
            'photo_urls' => $user->photo_urls,
        ];
    }

    /**
     * Platform-wide dashboard for SuperAdmin: cross-org totals, top organizations, trend, recent activity.
     */
    private function superAdminDashboard(): array
    {
        $organizations = Organization::all();
        $events = Event::query()->get();
        $eventsQuery = fn () => Event::query();
        $usersByRole = $this->userCountsByRole();
        $upcomingEvents = $this->upcomingEvents($eventsQuery);

        return [
            'organizations' => [
                'total' => $organizations->count(),
                'active' => $organizations->where('is_active', true)->count(),
                'inactive' => $organizations->where('is_active', false)->count(),
            ],
            'events' => $this->eventsSummary($events),
            'attendees' => ['total' => Attendee::query()->count()],
            'users' => [
                'total' => (int) $usersByRole->sum(),
                'super_admins' => (int) $usersByRole->get('super_admin', 0),
                'org_admins' => (int) $usersByRole->get('org_admin', 0),
                'checkers' => (int) $usersByRole->get('checker', 0),
            ],
            'registrations' => ['total' => Attendee::query()->whereHas('event')->count()],
            'top_organizations' => $this->topOrganizations($organizations),
            'next_upcoming_event' => $upcomingEvents[0] ?? null,
            'upcoming_events' => $upcomingEvents,
            'attendance_rate_trend' => $this->attendanceRateTrend($eventsQuery),
            ...$this->registrationInsights->build($eventsQuery()),
            'recent_activity' => $this->recentActivity(null),
        ];
    }

    /**
     * Organization-scoped dashboard for OrgAdmin: org totals, today's sessions, breakdowns, recent activity.
     */
    private function orgAdminDashboard(User $user): array
    {
        $organization = $user->organization;
        $events = Event::query()->get();
        $eventsQuery = fn () => Event::query();
        $upcomingEvents = $this->upcomingEvents($eventsQuery);
        $usersByRole = $this->userCountsByRole($organization->id);

        return [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'code' => $organization->code,
                'logo_url' => $organization->logo_path ? Storage::disk('public')->url($organization->logo_path) : null,
            ],
            'events' => $this->eventsSummary($events),
            'attendees' => ['total' => Attendee::query()->count()],
            'users' => [
                'total' => (int) $usersByRole->sum(),
                'org_admins' => (int) $usersByRole->get('org_admin', 0),
                'checkers' => (int) $usersByRole->get('checker', 0),
            ],
            'unions' => ['total' => $organization->unions()->count()],
            'missions' => ['total' => $organization->missions()->count()],
            'registrations' => ['total' => Attendee::query()->whereHas('event')->count()],
            'next_upcoming_event' => $upcomingEvents[0] ?? null,
            'upcoming_events' => $upcomingEvents,
            'today_sessions' => $this->todaySessions($eventsQuery),
            'attendance_rate_trend' => $this->attendanceRateTrend($eventsQuery),
            ...$this->registrationInsights->build($eventsQuery()),
            'recent_activity' => $this->recentActivity($organization->id),
        ];
    }

    /**
     * Operational dashboard for Checker: accessible events, today's sessions, personal scan stats.
     */
    private function checkerDashboard(User $user): array
    {
        $restricted = $user->accessibleEvents()->exists();
        $eventsQuery = $restricted
            ? fn () => Event::query()->whereNull('archived_at')->whereIn('id', $user->accessibleEvents()->pluck('events.id'))
            : fn () => Event::query()->whereNull('archived_at');

        return [
            'access_restricted' => $restricted,
            ...$this->registrationInsights->build($eventsQuery()),
            'accessible_events_count' => $eventsQuery()->count(),
            'upcoming_events' => $this->upcomingEvents($eventsQuery),
            'today_sessions' => $this->todaySessions($eventsQuery),
            'attendance_rate_trend' => $this->attendanceRateTrend($eventsQuery),
            'my_scan_stats' => $this->myScanStats($user),
            'recent_scans' => $this->recentScans($user),
        ];
    }

    private function userCountsByRole(?int $organizationId = null): Collection
    {
        return User::query()
            ->when($organizationId !== null, fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->selectRaw('role, count(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role');
    }

    private function myScanStats(User $user): array
    {
        return [
            'today' => $this->scansRecordedBy($user)->whereDate('created_at', today())->count(),
            'total' => $this->scansRecordedBy($user)->count(),
        ];
    }

    /**
     * Scans the user recorded on events that are not archived.
     *
     * @return Builder<AttendanceRecord>
     */
    private function scansRecordedBy(User $user): Builder
    {
        return AttendanceRecord::query()
            ->where('recorded_by', $user->id)
            ->whereHas('attendee.event', fn (Builder $query) => $query->whereNull('archived_at'));
    }

    private function recentScans(User $user): array
    {
        return $this->scansRecordedBy($user)
            ->with(['attendee.event', 'eventSession'])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (AttendanceRecord $record) => [
                'id' => $record->id,
                'attendee_name' => trim("{$record->attendee->first_name} {$record->attendee->last_name}"),
                'event_id' => $record->attendee->event->id,
                'event_name' => $record->attendee->event->name,
                'session_name' => $record->eventSession?->name,
                'method' => $record->method,
                'check_in_at' => $record->check_in_at,
                'check_out_at' => $record->check_out_at,
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Event>  $events
     */
    private function eventsSummary(Collection $events): array
    {
        $today = today();
        $statusCounts = $events->countBy(fn (Event $event) => $event->status->value);

        return [
            'total' => $events->count(),
            'draft' => $statusCounts->get('draft', 0),
            'published' => $statusCounts->get('published', 0),
            'completed' => $statusCounts->get('completed', 0),
            'cancelled' => $statusCounts->get('cancelled', 0),
            'upcoming' => $events->filter(fn (Event $event) => $event->start_date >= $today && $event->status !== EventStatus::Cancelled)->count(),
            'ongoing' => $events->filter(fn (Event $event) => $event->start_date <= $today && $event->end_date >= $today && $event->status !== EventStatus::Cancelled)->count(),
        ];
    }

    /**
     * @param  Closure(): Builder<Event>  $eventsQuery
     */
    private function upcomingEvents(Closure $eventsQuery, int $limit = 5): array
    {
        return $eventsQuery()
            ->withCount('attendees')
            ->whereIn('status', [EventStatus::Draft->value, EventStatus::Published->value])
            ->where('start_date', '>=', today())
            ->orderBy('start_date')
            ->limit($limit)
            ->get()
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'name' => $event->name,
                'start_date' => $event->start_date->toDateString(),
                'end_date' => $event->end_date->toDateString(),
                'venue' => $event->venue,
                'status' => $event->status,
                'registrations_count' => $event->attendees_count,
                'days_until_start' => (int) today()->diffInDays($event->start_date),
            ])
            ->all();
    }

    /**
     * Sessions scheduled today, with per-session check-in progress, ordered by start time.
     *
     * @param  Closure(): Builder<Event>  $eventsQuery
     */
    private function todaySessions(Closure $eventsQuery): array
    {
        $eventIds = $eventsQuery()->pluck('id');

        return EventSession::query()
            ->whereIn('event_id', $eventIds)
            ->whereDate('session_date', today())
            ->withCount([
                'attendanceRecords as checked_in_count' => fn ($q) => $q->whereNotNull('check_in_at'),
                'attendanceRecords as checked_out_count' => fn ($q) => $q->whereNotNull('check_out_at'),
            ])
            ->with(['event' => fn ($q) => $q->withCount('attendees')])
            ->orderBy('start_time')
            ->get()
            ->map(fn (EventSession $session) => [
                'id' => $session->id,
                'event_id' => $session->event_id,
                'event_name' => $session->event->name,
                'name' => $session->name,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'check_in_opens_at' => $session->checkInOpensAt(),
                'registered' => $session->event->attendees_count,
                'checked_in' => $session->checked_in_count,
                'checked_out' => $session->checked_out_count,
            ])
            ->all();
    }

    /**
     * Average session attendance rate for the last N completed events, ordered by end_date descending.
     *
     * @param  Closure(): Builder<Event>  $eventsQuery
     */
    private function attendanceRateTrend(Closure $eventsQuery, int $limit = 5): array
    {
        $events = $eventsQuery()
            ->where('status', EventStatus::Completed->value)
            ->withCount([
                'attendees',
                'sessions',
                'attendees as checked_in_registrations_count' => fn (Builder $query) => $query
                    ->whereHas('attendanceRecords', fn (Builder $records) => $records->whereNotNull('check_in_at')),
            ])
            ->orderByDesc('end_date')
            ->limit($limit)
            ->get();
        $eventIds = $events->modelKeys();
        $sessionCheckIns = AttendanceRecord::query()
            ->whereNotNull('check_in_at')
            ->join('attendees', 'attendees.id', '=', 'attendance_records.attendee_id')
            ->whereIn('attendees.event_id', $eventIds)
            ->selectRaw('attendees.event_id, COUNT(attendance_records.id) as session_check_ins')
            ->groupBy('attendees.event_id')
            ->pluck('session_check_ins', 'attendees.event_id');

        return $events
            ->map(function (Event $event) use ($sessionCheckIns) {
                $registered = (int) $event->attendees_count;
                $uniqueCheckedIn = (int) $event->checked_in_registrations_count;
                $sessions = (int) $event->sessions_count;
                $eventSessionCheckIns = (int) $sessionCheckIns->get($event->id, 0);
                $opportunities = $registered * $sessions;

                return [
                    'event_id' => $event->id,
                    'event_name' => $event->name,
                    'end_date' => $event->end_date->toDateString(),
                    'registered' => $registered,
                    'checked_in' => $uniqueCheckedIn,
                    'sessions' => $sessions,
                    'session_check_ins' => $eventSessionCheckIns,
                    'coverage_rate' => $registered > 0 ? round($uniqueCheckedIn / $registered * 100, 1) : 0,
                    'rate' => $opportunities > 0 ? round($eventSessionCheckIns / $opportunities * 100, 1) : 0,
                ];
            })
            ->all();
    }

    /**
     * Top organizations by total registrations (descending), limited to $limit.
     *
     * @param  Collection<int, Organization>  $organizations
     */
    private function topOrganizations(Collection $organizations, int $limit = 5): array
    {
        $organizations->loadCount(['events', 'attendees']);
        $organizationIds = $organizations->pluck('id');
        $registrationCounts = Event::query()
            ->leftJoin('attendees', 'attendees.event_id', '=', 'events.id')
            ->whereIn('events.organization_id', $organizationIds)
            ->selectRaw('events.organization_id, COUNT(attendees.id) as total_registrations')
            ->groupBy('events.organization_id')
            ->pluck('total_registrations', 'events.organization_id');

        return $organizations
            ->map(fn (Organization $organization) => [
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'total_events' => (int) $organization->events_count,
                'total_attendees' => (int) $organization->attendees_count,
                'total_registrations' => (int) $registrationCounts->get($organization->id, 0),
            ])
            ->sortByDesc('total_registrations')
            ->take($limit)
            ->values()
            ->all();
    }

    private function recentActivity(?int $organizationId, int $limit = 10): array
    {
        return AuditLog::query()
            ->with('user')
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user?->name,
                'auditable_type' => $log->auditable_type ? class_basename($log->auditable_type) : null,
                'created_at' => $log->created_at,
            ])
            ->all();
    }
}
