<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_super_admin_test_scan_returns_preview_without_writes_even_before_session_opens(): void
    {
        $org = Organization::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->addDay()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);
        $registration = $this->registeredAttendee($org, $event);

        $response = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $registration->qr_token,
            'test_mode' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('test_mode', true)
            ->assertJsonPath('message', 'Test scan successful. No attendance recorded.')
            ->assertJsonPath('data.id', null)
            ->assertJsonPath('data.event_registration.id', $registration->id)
            ->assertJsonPath('data.event_registration.attendee.id', $registration->attendee_id);
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_test_scan_does_not_change_existing_attendance_or_write_audit_log(): void
    {
        $org = Organization::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->addDay()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
        ]);
        $registration = $this->registeredAttendee($org, $event);
        $existing = AttendanceRecord::factory()->for($registration)->for($session)->create();
        $originalAttributes = (array) DB::table('attendance_records')->where('id', $existing->id)->first();
        ksort($originalAttributes);
        $auditCount = AuditLog::count();

        $response = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $registration->qr_token,
            'test_mode' => true,
        ]);

        $response->assertOk()->assertJsonPath('test_mode', true);
        $this->assertDatabaseCount('attendance_records', 1);
        $freshAttributes = (array) DB::table('attendance_records')->where('id', $existing->id)->first();
        ksort($freshAttributes);
        $this->assertSame($originalAttributes, $freshAttributes);
        $this->assertSame($auditCount, AuditLog::count());
    }

    public function test_non_super_admin_cannot_request_test_scan(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $orgAdmin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create();
        $registration = $this->registeredAttendee($org, $event);

        foreach ([$checker, $orgAdmin] as $user) {
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/scan', [
                'event_id' => $event->id,
                'session_id' => $session->id,
                'qr_token' => $registration->qr_token,
                'test_mode' => true,
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_test_scan_still_rejects_unregistered_qr_and_mismatched_session(): void
    {
        $org = Organization::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        $event = Event::factory()->for($org)->create();
        $otherEvent = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create();
        $otherSession = EventSession::factory()->for($otherEvent)->create();
        $registration = $this->registeredAttendee($org, $event);
        $otherRegistration = $this->registeredAttendee($org, $otherEvent);

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => 'invalid-token',
            'test_mode' => true,
        ])->assertUnprocessable();

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $otherSession->id,
            'qr_token' => $registration->qr_token,
            'test_mode' => true,
        ])->assertNotFound();

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $otherRegistration->qr_token,
            'test_mode' => true,
        ])->assertUnprocessable();

        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $registration->qr_token,
            'test_mode' => 'invalid',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('attendance_records', 0);
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
