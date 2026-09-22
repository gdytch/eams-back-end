<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardRegistrationInsightsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_all_admin_dashboards_select_one_territory_per_registration(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        $organization = Organization::factory()->create();
        $union = Union::factory()->for($organization)->create(['name' => 'Shared name']);
        $mission = Mission::factory()->for($organization)->for($union)->create(['name' => 'Shared name']);
        $events = Event::factory()->for($organization)->count(2)->create();
        foreach (['union', 'mission', null] as $level) {
            $attendee = Attendee::factory()->for($organization)->create([
                'organization_level' => $level,
                'union_id' => $union->id,
                'mission_id' => $mission->id,
            ]);
            foreach ($events as $event) {
                $registration = EventRegistration::factory()->for($event)->for($attendee)->create(['registered_at' => now()]);
                if ($level === 'mission') {
                    AttendanceRecord::factory()->for($registration)->count(2)->create(['check_in_at' => now()]);
                }
            }
        }
        $admin = User::factory()->superAdmin()->create();
        foreach (['/api/v1/dashboard', '/api/v1/reports/dashboard', "/api/v1/organizations/{$organization->id}/reports/dashboard"] as $url) {
            $response = $this->actingAs($admin, 'sanctum')->getJson($url)->assertOk();
            $response->assertJsonPath('registration_summary.total', 6)
                ->assertJsonPath('registration_summary.checked_in', 2)
                ->assertJsonPath('registration_summary.last_14_days', 6)
                ->assertJsonCount(3, 'registrations_by_organization_level')
                ->assertJsonCount(14, 'registration_trend')
                ->assertJsonPath('registration_trend.13.registrations', 6)
                ->assertJsonPath('registration_trend.0.registrations', 0);
            $rows = collect($response->json('registrations_by_organization_level'))->keyBy('organization_level');
            $this->assertSame(2, $rows['union']['registrations']);
            $this->assertSame(2, $rows['mission']['registrations']);
            $this->assertSame(0, $rows['union']['checked_in']);
            $this->assertSame('Unassigned', $rows['unassigned']['organization_name']);
            $this->assertNotSame($rows['union']['key'], $rows['mission']['key']);
        }
    }

    public function test_insights_respect_organization_and_checker_event_access(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $allowed = Event::factory()->for($organization)->create();
        $hidden = Event::factory()->for($organization)->create();
        $foreign = Event::factory()->for($otherOrganization)->create();
        foreach ([$allowed, $hidden, $foreign] as $event) {
            EventRegistration::factory()->for($event)->for(Attendee::factory()->for($event->organization))->create(['registered_at' => now()]);
        }
        $admin = User::factory()->orgAdmin()->for($organization)->create();
        foreach (['/api/v1/dashboard', "/api/v1/organizations/{$organization->id}/reports/dashboard"] as $url) {
            $this->actingAs($admin, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('registration_summary.total', 2);
        }
        $checker = User::factory()->checker()->for($organization)->create();
        $checker->accessibleEvents()->attach($allowed);
        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('registration_summary.total', 1)
            ->assertJsonPath('registration_summary.last_14_days', 1);
    }

    public function test_empty_dashboard_and_missing_selected_territory_are_explicit(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('registration_summary.total', 0)
            ->assertJsonPath('registration_summary.check_in_rate', 0)
            ->assertJsonPath('registrations_by_organization_level', []);
        $organization = Organization::factory()->create();
        $union = Union::factory()->for($organization)->create();
        $attendee = Attendee::factory()->for($organization)->create(['organization_level' => 'mission', 'union_id' => $union->id, 'mission_id' => null]);
        EventRegistration::factory()->for(Event::factory()->for($organization))->for($attendee)->create(['registered_at' => now()->subDays(20)]);
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('registrations_by_organization_level.0.organization_name', 'Unassigned')
            ->assertJsonPath('registrations_by_organization_level.0.organization_level', 'mission')
            ->assertJsonPath('registration_summary.last_14_days', 0);
    }
}
