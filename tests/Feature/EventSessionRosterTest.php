<?php

namespace Tests\Feature;

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

class EventSessionRosterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setUpRoster(Organization $org): array
    {
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create();
        $union = Union::factory()->for($org)->create();
        $mission = Mission::factory()->for($org)->for($union)->create();

        $earlyCheckIn = Attendee::factory()->for($org)->for($union)->for($mission)->create([
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
        ]);
        $earlyRegistration = EventRegistration::factory()->for($event)->for($earlyCheckIn)->create();
        $earlyRegistration->attendanceRecords()->create([
            'event_session_id' => $session->id,
            'check_in_at' => now()->subMinutes(30),
            'method' => 'qr',
            'recorded_by' => 1,
        ]);

        $lateCheckIn = Attendee::factory()->for($org)->for($union)->for($mission)->create([
            'first_name' => 'Ben',
            'last_name' => 'Reyes',
        ]);
        $lateRegistration = EventRegistration::factory()->for($event)->for($lateCheckIn)->create();
        $lateRegistration->attendanceRecords()->create([
            'event_session_id' => $session->id,
            'check_in_at' => now()->subMinutes(5),
            'method' => 'qr',
            'recorded_by' => 1,
        ]);

        $absentee = Attendee::factory()->for($org)->for($union)->for($mission)->create([
            'first_name' => 'Cara',
            'last_name' => 'Diaz',
        ]);
        $absentRegistration = EventRegistration::factory()->for($event)->for($absentee)->create();

        return compact('event', 'session', 'earlyRegistration', 'lateRegistration', 'absentRegistration');
    }

    public function test_roster_returns_all_registrations_sorted_by_check_in(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        ['event' => $event, 'session' => $session] = $this->setUpRoster($org);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/sessions/{$session->id}/roster");

        $response->assertOk();
        $response->assertJsonPath('status', 'all');
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('data.0.attendee_name', 'Ana Cruz');
        $response->assertJsonPath('data.0.attendance_status', 'Present');
        $response->assertJsonPath('data.1.attendee_name', 'Ben Reyes');
        $response->assertJsonPath('data.2.attendee_name', 'Cara Diaz');
        $response->assertJsonPath('data.2.attendance_status', 'Absent');
        $response->assertJsonPath('data.2.check_in_at', null);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'event_registration_id',
                    'attendee_name',
                    'attendee_photo_url',
                    'union',
                    'mission',
                    'attendance_status',
                    'check_in_at',
                    'check_out_at',
                ],
            ],
        ]);
    }

    public function test_roster_filters_present_only(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        ['event' => $event, 'session' => $session] = $this->setUpRoster($org);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/sessions/{$session->id}/roster?status=present");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.attendance_status', 'Present');
        $response->assertJsonPath('data.1.attendance_status', 'Present');
    }

    public function test_roster_filters_absent_only(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        ['event' => $event, 'session' => $session] = $this->setUpRoster($org);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/sessions/{$session->id}/roster?status=Absent");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.attendee_name', 'Cara Diaz');
        $response->assertJsonPath('data.0.attendance_status', 'Absent');
    }

    public function test_roster_rejects_invalid_status_filter(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        ['event' => $event, 'session' => $session] = $this->setUpRoster($org);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/sessions/{$session->id}/roster?status=bogus");

        $response->assertUnprocessable();
    }

    public function test_roster_requires_view_permission(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org2)->create();
        ['event' => $event, 'session' => $session] = $this->setUpRoster($org1);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/sessions/{$session->id}/roster");

        $response->assertNotFound();
    }

    public function test_roster_rejects_a_session_from_another_event(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $otherEvent = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($otherEvent)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/sessions/{$session->id}/roster");

        $response->assertNotFound();
    }

    public function test_roster_paginates_using_per_page(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        ['event' => $event, 'session' => $session] = $this->setUpRoster($org);

        $response = $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/sessions/{$session->id}/roster?per_page=2");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.last_page', 2);
    }
}
