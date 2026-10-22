<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceScanTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function registeredAttendee(Organization $org, Event $event): Attendee
    {
        return Attendee::factory()->for($event)->for($org)->create();
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
            'attendee_id' => $registration->id,
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
            ->assertJsonPath('data.attendee_id', $registration->id)
            ->assertJsonPath('data.attendee.attendee_id', $registration->id)
            ->assertJsonPath('data.attendee.attendee.id', $registration->id);
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

    private function openSession(Event $event): EventSession
    {
        return EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);
    }

    public function test_scan_only_checks_in_the_attendee_of_the_scanned_event(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $otherEvent = Event::factory()->for($org)->create();
        $session = $this->openSession($event);
        $user = User::factory()->attendee()->create();
        $mine = Attendee::factory()->for($event)->for($user)->create();
        $sameUserElsewhere = Attendee::factory()->for($otherEvent)->for($user)->create();

        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $mine->qr_token,
        ])->assertCreated()->assertJsonPath('data.attendee_id', $mine->id);

        // The same person's badge for another event does not work here.
        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'qr_token' => $sameUserElsewhere->qr_token,
        ])->assertUnprocessable()->assertJsonValidationErrors('qr_token');

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $mine->id, 'event_session_id' => $session->id]);
    }

    public function test_manual_attendance_takes_an_attendee_id(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = $this->openSession($event);
        $attendee = $this->registeredAttendee($org, $event);

        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/manual', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'attendee_id' => $attendee->id,
        ])->assertCreated()->assertJsonPath('data.attendee_id', $attendee->id)->assertJsonPath('data.method', 'manual');

        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $attendee->id, 'method' => 'manual']);
    }

    public function test_manual_attendance_rejects_the_removed_event_registration_id(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = $this->openSession($event);
        $attendee = $this->registeredAttendee($org, $event);
        $attendee->forceFill(['legacy_registration_id' => 777])->save();

        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/manual', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'event_registration_id' => 777,
        ])->assertUnprocessable()->assertJsonValidationErrors(['attendee_id']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_manual_attendance_requires_an_attendee_and_rejects_one_from_another_event(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = $this->openSession($event);
        $foreign = $this->registeredAttendee($org, Event::factory()->for($org)->create());

        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/manual', [
            'event_id' => $event->id,
            'session_id' => $session->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['attendee_id']);

        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/manual', [
            'event_id' => $event->id,
            'session_id' => $session->id,
            'attendee_id' => $foreign->id,
        ])->assertNotFound();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_check_out_records_the_time_for_the_attendees_event(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create(['requires_check_out' => true]);
        $session = $this->openSession($event);
        $attendee = $this->registeredAttendee($org, $event);
        $record = AttendanceRecord::factory()->for($attendee)->for($session)->create();

        $this->actingAs($checker, 'sanctum')->postJson("/api/v1/attendance/{$record->id}/check-out")
            ->assertOk()->assertJsonPath('data.attendee_id', $attendee->id);

        $this->assertNotNull($record->fresh()->check_out_at);
    }

    public function test_session_attendance_lists_records_with_their_attendee(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = $this->openSession($event);
        $attendee = $this->registeredAttendee($org, $event);
        $other = $this->registeredAttendee($org, Event::factory()->for($org)->create());
        AttendanceRecord::factory()->for($attendee)->for($session)->create();
        AttendanceRecord::factory()->for($other)->for($session)->create();

        $this->actingAs($checker, 'sanctum')
            ->getJson("/api/v1/events/{$event->id}/sessions/{$session->id}/attendance?sort_by=last_name")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attendee_id', $attendee->id)
            ->assertJsonPath('data.0.attendee.attendee.id', $attendee->id)
            ->assertJsonPath('data.0.event_registration.attendee.id', $attendee->id);
    }
}
