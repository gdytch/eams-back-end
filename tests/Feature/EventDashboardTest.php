<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EventDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_event_dashboard_is_scoped_to_the_requested_event(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($organization)->create();
        $event = Event::factory()->for($organization)->create([
            'start_date' => '2026-09-22',
            'end_date' => '2026-09-23',
        ]);
        $otherEvent = Event::factory()->for($organization)->create();
        $session = EventSession::factory()->for($event)->create(['session_date' => '2026-09-22']);
        $secondSession = EventSession::factory()->for($event)->create(['session_date' => '2026-09-23']);
        $otherSession = EventSession::factory()->for($otherEvent)->create();
        $union = Union::factory()->for($organization)->create(['name' => 'North Union']);
        $mission = Mission::factory()->for($organization)->for($union)->create(['name' => 'South Mission']);

        $unionAttendees = Attendee::factory()->for($organization)->count(2)->create([
            'organization_level' => 'union',
            'union_id' => $union->id,
        ]);
        $missionAttendee = Attendee::factory()->for($organization)->create([
            'organization_level' => 'mission',
            'union_id' => $union->id,
            'mission_id' => $mission->id,
        ]);

        $firstUnionRegistration = null;
        foreach ($unionAttendees as $index => $attendee) {
            $registration = EventRegistration::factory()->for($event)->for($attendee)->create();
            $firstUnionRegistration ??= $registration;
            AttendanceRecord::factory()->for($registration)->for($session)->create([
                'check_in_at' => '2026-09-22 '.(9 + $index).':15:00',
            ]);
        }
        AttendanceRecord::factory()->for($firstUnionRegistration)->for($secondSession)->create([
            'check_in_at' => '2026-09-23 09:15:00',
        ]);
        EventRegistration::factory()->for($event)->for($missionAttendee)->create();

        $otherRegistration = EventRegistration::factory()->for($otherEvent)->for($missionAttendee)->create();
        AttendanceRecord::factory()->for($otherRegistration)->for($otherSession)->create([
            'check_in_at' => '2026-09-22 11:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/reports/dashboard");

        $response->assertOk()
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonPath('attendance.registered', 3)
            ->assertJsonPath('attendance.checked_in', 2)
            ->assertJsonPath('attendance.not_checked_in', 1)
            ->assertJsonPath('attendance.attendance_rate', 50)
            ->assertJsonPath('attendance.check_in_rate', 66.7)
            ->assertJsonPath('top_check_ins_by_organization.0.organization_name', 'North Union')
            ->assertJsonPath('top_check_ins_by_organization.0.checked_in', 2)
            ->assertJsonPath('top_check_ins_by_organization.0.unique_attendees', 2)
            ->assertJsonPath('top_check_ins_by_organization.0.check_ins', 3)
            ->assertJsonPath('top_check_ins_by_organization.0.attendance_rate', 75)
            ->assertJsonPath('attendance_rate_trend.granularity', 'session');

        $unionSeries = collect($response->json('attendance_rate_trend.series'))
            ->firstWhere('organization_name', 'North Union');
        $missionSeries = collect($response->json('attendance_rate_trend.series'))
            ->firstWhere('organization_name', 'South Mission');

        $this->assertSame([100, 50], $unionSeries['data']);
        $this->assertSame([2, 1], $unionSeries['checked_in']);
        $this->assertSame(3, $unionSeries['total_check_ins']);
        $this->assertSame(75, $unionSeries['overall_rate']);
        $this->assertSame(0, array_sum($missionSeries['data']));
    }

    public function test_event_dashboard_honors_event_authorization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($organization)->create();
        $event = Event::factory()->for($otherOrganization)->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/reports/dashboard")
            ->assertNotFound();
    }
}
