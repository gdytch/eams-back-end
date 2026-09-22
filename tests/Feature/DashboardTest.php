<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->getJson('/api/v1/dashboard');

        $response->assertUnauthorized();
    }

    public function test_attendee_cannot_access_staff_dashboard(): void
    {
        $attendee = User::factory()->attendee()->create();

        $response = $this->actingAs($attendee, 'sanctum')->getJson('/api/v1/dashboard');

        $response->assertForbidden();
    }

    public function test_super_admin_dashboard_structure_and_totals(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $org1 = Organization::factory()->state(['is_active' => true])->create();
        $org2 = Organization::factory()->state(['is_active' => false])->create();

        Event::factory()->for($org1)->count(2)->create();
        Event::factory()->for($org2)->count(1)->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'role',
            'user',
            'generated_at',
            'organizations',
            'events',
            'attendees',
            'users',
            'registrations',
            'top_organizations',
            'next_upcoming_event',
            'upcoming_events',
            'attendance_rate_trend',
            'recent_activity',
        ]);
        $response->assertJsonPath('organizations.total', 2);
        $response->assertJsonPath('organizations.active', 1);
        $response->assertJsonPath('events.total', 3);
    }

    public function test_org_admin_dashboard_is_scoped_to_their_organization(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org1)->create();

        Event::factory()->for($org1)->count(2)->create();
        Event::factory()->for($org2)->count(5)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'role',
            'user',
            'organization',
            'events',
            'attendees',
            'users',
            'unions',
            'missions',
            'registrations',
            'next_upcoming_event',
            'upcoming_events',
            'today_sessions',
            'attendance_rate_trend',
            'registrations_by_organization_level',
            'registration_summary',
            'registration_trend',
            'recent_activity',
        ]);
        $response->assertJsonPath('organization.id', $org1->id);
        $response->assertJsonPath('events.total', 2);
    }

    public function test_org_admin_dashboard_shows_todays_sessions_with_progress(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();

        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create(['session_date' => now()->toDateString()]);

        $attendee = Attendee::factory()->for($org)->create();
        $registration = EventRegistration::factory()->for($event)->for($attendee)->create();
        AttendanceRecord::factory()->for($registration)->for($session)->create(['check_in_at' => now()]);

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('today_sessions.0.id', $session->id);
        $response->assertJsonPath('today_sessions.0.registered', 1);
        $response->assertJsonPath('today_sessions.0.checked_in', 1);
    }

    public function test_dashboard_attendance_rate_uses_session_attendance_opportunities(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->state(['status' => 'completed'])->create();
        $firstSession = EventSession::factory()->for($event)->create();
        $secondSession = EventSession::factory()->for($event)->create();
        $attendees = Attendee::factory()->for($org)->count(2)->create();
        $registrations = $attendees->map(
            fn (Attendee $attendee) => EventRegistration::factory()->for($event)->for($attendee)->create()
        );

        AttendanceRecord::factory()->for($registrations[0])->for($firstSession)->create();
        AttendanceRecord::factory()->for($registrations[1])->for($firstSession)->create();
        AttendanceRecord::factory()->for($registrations[0])->for($secondSession)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonPath('attendance_rate_trend.0.event_id', $event->id)
            ->assertJsonPath('attendance_rate_trend.0.registered', 2)
            ->assertJsonPath('attendance_rate_trend.0.sessions', 2)
            ->assertJsonPath('attendance_rate_trend.0.session_check_ins', 3)
            ->assertJsonPath('attendance_rate_trend.0.coverage_rate', 100)
            ->assertJsonPath('attendance_rate_trend.0.rate', 75);
    }

    public function test_checker_dashboard_has_no_access_restriction_by_default(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        Event::factory()->for($org)->count(3)->create();

        $response = $this->actingAs($checker, 'sanctum')->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'role',
            'user',
            'access_restricted',
            'accessible_events_count',
            'upcoming_events',
            'today_sessions',
            'attendance_rate_trend',
            'my_scan_stats',
            'recent_scans',
        ]);
        $response->assertJsonPath('access_restricted', false);
        $response->assertJsonPath('accessible_events_count', 3);
    }

    public function test_checker_dashboard_is_restricted_to_granted_events(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $grantedEvent = Event::factory()->for($org)->create();
        Event::factory()->for($org)->create();

        $checker->accessibleEvents()->attach($grantedEvent);

        $response = $this->actingAs($checker, 'sanctum')->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('access_restricted', true);
        $response->assertJsonPath('accessible_events_count', 1);
    }

    public function test_checker_dashboard_reports_own_scan_stats(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $event = Event::factory()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create();
        $registration = EventRegistration::factory()->for($event)->for($attendee)->create();

        AttendanceRecord::factory()->for($registration)->create([
            'check_in_at' => now(),
            'recorded_by' => $checker->id,
        ]);

        $response = $this->actingAs($checker, 'sanctum')->getJson('/api/v1/dashboard');

        $response->assertOk();
        $response->assertJsonPath('my_scan_stats.today', 1);
        $response->assertJsonPath('my_scan_stats.total', 1);
        $response->assertJsonPath('recent_scans.0.attendee_name', trim("{$attendee->first_name} {$attendee->last_name}"));
    }
}
