<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReportDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unauthenticated_user_cannot_access_organization_dashboard(): void
    {
        $org = Organization::factory()->create();

        $response = $this->getJson("/api/v1/organizations/{$org->id}/reports/dashboard");

        $response->assertUnauthorized();
    }

    public function test_checker_cannot_access_organization_dashboard(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/dashboard");

        $response->assertForbidden();
    }

    public function test_org_admin_can_access_their_organization_dashboard(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/dashboard");

        $response->assertOk();
        $response->assertJsonStructure([
            'organization_id',
            'organization_name',
            'events',
            'attendees',
            'registrations',
            'latest_event',
            'next_upcoming_event',
            'attendance_rate_trend',
            'breakdown_by_union',
            'breakdown_by_mission',
        ]);
    }

    public function test_super_admin_can_access_any_organization_dashboard(): void
    {
        $org = Organization::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/dashboard");

        $response->assertOk();
    }

    public function test_org_admin_cannot_access_other_organization_dashboard(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $orgAdmin1 = User::factory()->orgAdmin()->for($org1)->create();

        $response = $this->actingAs($orgAdmin1, 'sanctum')
            ->getJson("/api/v1/organizations/{$org2->id}/reports/dashboard");

        $response->assertForbidden();
    }

    public function test_organization_dashboard_events_counts(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();

        Event::factory()->for($org)->state(['status' => 'draft'])->create();
        Event::factory()->for($org)->state(['status' => 'draft'])->create();
        Event::factory()->for($org)->state(['status' => 'published'])->create();
        Event::factory()->for($org)->state(['status' => 'completed'])->create();
        Event::factory()->for($org)->state(['status' => 'cancelled'])->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/dashboard");

        $response->assertOk();
        $response->assertJsonPath('events.total', 5);
        $response->assertJsonPath('events.draft', 2);
        $response->assertJsonPath('events.published', 1);
        $response->assertJsonPath('events.completed', 1);
        $response->assertJsonPath('events.cancelled', 1);
    }

    public function test_organization_dashboard_attendees_and_registrations_counts(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();

        Attendee::factory()->for($org)->count(5)->create();

        $event1 = Event::factory()->for($org)->create();
        $event2 = Event::factory()->for($org)->create();

        $attendees = Attendee::where('organization_id', $org->id)->take(3)->get();
        foreach ($attendees as $attendee) {
            EventRegistration::factory()->for($event1)->for($attendee)->create();
            EventRegistration::factory()->for($event2)->for($attendee)->create();
        }

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/dashboard");

        $response->assertOk();
        $response->assertJsonPath('attendees.total', 5);
        $response->assertJsonPath('registrations.total', 6);
    }

    public function test_organization_dashboard_latest_event_stats(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();

        $pastEvent = Event::factory()->for($org)->state(['status' => 'completed'])->create();
        $attendee = Attendee::factory()->for($org)->create();
        $reg = EventRegistration::factory()->for($pastEvent)->for($attendee)->create();

        AttendanceRecord::factory()->for($reg)->create(['check_in_at' => now(), 'check_out_at' => now()]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/dashboard");

        $response->assertOk();
        $response->assertJsonPath('latest_event.id', $pastEvent->id);
        $response->assertJsonPath('latest_event.stats.registered', 1);
        $response->assertJsonPath('latest_event.stats.checked_in', 1);
        $response->assertJsonPath('latest_event.stats.check_in_rate', 100);
    }

    public function test_organization_dashboard_next_upcoming_event(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();

        Event::factory()->for($org)->state(['status' => 'completed'])->create();
        $upcomingEvent = Event::factory()->for($org)->state(['status' => 'published', 'start_date' => now()->addDays(5)])->create();

        $attendee = Attendee::factory()->for($org)->create();
        EventRegistration::factory()->for($upcomingEvent)->for($attendee)->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/dashboard");

        $response->assertOk();
        $response->assertJsonPath('next_upcoming_event.id', $upcomingEvent->id);
        $response->assertJsonPath('next_upcoming_event.registrations_count', 1);
        $response->assertJsonPath('next_upcoming_event.days_until_start', 5);
    }

    public function test_organization_dashboard_attendance_rate_trend(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();

        $event1 = Event::factory()->for($org)->state(['status' => 'completed'])->create();
        $event2 = Event::factory()->for($org)->state(['status' => 'completed'])->create();

        $attendee1 = Attendee::factory()->for($org)->create();
        $attendee2 = Attendee::factory()->for($org)->create();

        $reg1 = EventRegistration::factory()->for($event1)->for($attendee1)->create();
        $reg2 = EventRegistration::factory()->for($event1)->for($attendee2)->create();
        AttendanceRecord::factory()->for($reg1)->create(['check_in_at' => now()]);

        $reg3 = EventRegistration::factory()->for($event2)->for($attendee1)->create();
        $reg4 = EventRegistration::factory()->for($event2)->for($attendee2)->create();
        AttendanceRecord::factory()->for($reg3)->create(['check_in_at' => now()]);
        AttendanceRecord::factory()->for($reg4)->create(['check_in_at' => now()]);

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson("/api/v1/organizations/{$org->id}/reports/dashboard");

        $response->assertOk();
        $this->assertIsArray($response->json('attendance_rate_trend'));
        $this->assertCount(2, $response->json('attendance_rate_trend'));
    }

    public function test_organization_isolation_in_dashboard(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $admin1 = User::factory()->orgAdmin()->for($org1)->create();

        Event::factory()->for($org1)->count(3)->create();
        Event::factory()->for($org2)->count(5)->create();

        $response = $this->actingAs($admin1, 'sanctum')
            ->getJson("/api/v1/organizations/{$org1->id}/reports/dashboard");

        $response->assertOk();
        $response->assertJsonPath('events.total', 3);
    }

    public function test_unauthenticated_user_cannot_access_global_dashboard(): void
    {
        $response = $this->getJson('/api/v1/reports/dashboard');

        $response->assertUnauthorized();
    }

    public function test_non_super_admin_cannot_access_global_dashboard(): void
    {
        $orgAdmin = User::factory()->orgAdmin()->create();

        $response = $this->actingAs($orgAdmin, 'sanctum')
            ->getJson('/api/v1/reports/dashboard');

        $response->assertForbidden();
    }

    public function test_super_admin_can_access_global_dashboard(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/reports/dashboard');

        $response->assertOk();
        $response->assertJsonStructure([
            'organizations',
            'events',
            'attendees',
            'registrations',
            'by_organization',
        ]);
    }

    public function test_global_dashboard_cross_organization_totals(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $org1 = Organization::factory()->state(['is_active' => true])->create();
        $org2 = Organization::factory()->state(['is_active' => true])->create();
        $org3 = Organization::factory()->state(['is_active' => false])->create();

        Event::factory()->for($org1)->count(2)->create();
        Event::factory()->for($org2)->count(3)->create();
        Event::factory()->for($org3)->count(1)->create();

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/reports/dashboard');

        $response->assertOk();
        $response->assertJsonPath('organizations.total', 3);
        $response->assertJsonPath('organizations.active', 2);
        $response->assertJsonPath('events.total', 6);
    }

    public function test_global_dashboard_by_organization_breakdown(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        Event::factory()->for($org1)->count(2)->create();
        Event::factory()->for($org2)->count(1)->create();

        $attendee1 = Attendee::factory()->for($org1)->create();
        $attendee2 = Attendee::factory()->for($org2)->create();

        $events1 = $org1->events;
        $events2 = $org2->events;

        EventRegistration::factory()->for($events1[0])->for($attendee1)->create();
        EventRegistration::factory()->for($events1[1])->for($attendee1)->create();

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/reports/dashboard');

        $response->assertOk();
        $this->assertIsArray($response->json('by_organization'));
        $this->assertCount(2, $response->json('by_organization'));
    }
}
