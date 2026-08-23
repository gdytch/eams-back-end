<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AttendanceScanTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function registeredAttendee(Organization $org, Event $event): EventRegistration
    {
        return EventRegistration::factory()
            ->for($event)
            ->for(Attendee::factory()->for($org))
            ->create();
    }

    public function test_scan_before_check_in_window_opens_is_rejected(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->addDay()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);
        $registration = $this->registeredAttendee($org, $event);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $registration->qr_token,
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_scan_within_check_in_window_succeeds(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);
        $registration = $this->registeredAttendee($org, $event);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $registration->qr_token,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendance_records', [
            'event_registration_id' => $registration->id,
            'event_session_id' => $session->id,
        ]);
    }

    public function test_duplicate_scan_returns_already_checked_in(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);
        $registration = $this->registeredAttendee($org, $event);

        $payload = [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $registration->qr_token,
        ];

        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/scan', $payload)->assertCreated();
        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/scan', $payload);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.qr_token.0', 'Already Checked In.');
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_org_admin_override_allows_check_in_before_window_opens(): void
    {
        $org = Organization::factory()->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->addDay()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);
        $registration = $this->registeredAttendee($org, $event);

        $response = $this->actingAs($orgAdmin, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $registration->qr_token,
            'override' => true,
        ]);

        $response->assertCreated();
    }

    public function test_checker_cannot_override_early_check_in(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->addDay()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);
        $registration = $this->registeredAttendee($org, $event);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $registration->qr_token,
            'override' => true,
        ]);

        $response->assertUnprocessable();
    }
}
