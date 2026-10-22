<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AttendanceSyncBatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function registeredAttendee(Organization $org, Event $event): Attendee
    {
        return Attendee::factory()->for($event)->for($org)->create();
    }

    public function test_sync_batch_single_valid_scan(): void
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

        $scannedAt = now()->subMinute()->format('Y-m-d H:i:s');

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [
                [
                    'client_ref' => 'ref-001',
                    'event_id' => $event->id,
                    'session_id' => $session->id,
                    'qr_token' => $registration->qr_token,
                    'scanned_at' => $scannedAt,
                    'method' => 'qr',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'created');
        $response->assertJsonPath('results.0.client_ref', 'ref-001');

        $this->assertDatabaseHas('attendance_records', [
            'attendee_id' => $registration->id,
            'event_session_id' => $session->id,
        ]);

        // Verify the check_in_at was set to the scanned_at time, not request time.
        $record = $registration->attendanceRecords->first();
        $this->assertTrue(
            $record->check_in_at->between(
                Carbon::parse($scannedAt)->subSecond(),
                Carbon::parse($scannedAt)->addSecond()
            )
        );
    }

    public function test_sync_batch_duplicate_returns_duplicate_status(): void
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

        // Create the first attendance record.
        $registration->attendanceRecords()->create([
            'event_session_id' => $session->id,
            'check_in_at' => now(),
            'method' => 'qr',
            'recorded_by' => $checker->id,
        ]);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [
                [
                    'client_ref' => 'ref-001',
                    'event_id' => $event->id,
                    'session_id' => $session->id,
                    'qr_token' => $registration->qr_token,
                    'method' => 'qr',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'duplicate');
        $response->assertJsonPath('results.0.client_ref', 'ref-001');
        $this->assertDatabaseCount('attendance_records', 1);
    }

    public function test_sync_batch_unauthorized_event_access_returns_error(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org2)->create();
        $event = Event::factory()->for($org1)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);
        $registration = $this->registeredAttendee($org1, $event);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [
                [
                    'client_ref' => 'ref-001',
                    'event_id' => $event->id,
                    'session_id' => $session->id,
                    'qr_token' => $registration->qr_token,
                    'method' => 'qr',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'error');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_sync_batch_before_check_in_window_returns_error(): void
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

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [
                [
                    'client_ref' => 'ref-001',
                    'event_id' => $event->id,
                    'session_id' => $session->id,
                    'qr_token' => $registration->qr_token,
                    'method' => 'qr',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.0.status', 'error');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_sync_batch_manual_method_with_attendee_id(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);
        $attendee = $this->registeredAttendee($org, $event);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [
                [
                    'client_ref' => 'ref-001',
                    'event_id' => $event->id,
                    'session_id' => $session->id,
                    'attendee_id' => $attendee->id,
                    'method' => 'manual',
                ],
            ],
        ]);

        $response->assertOk()->assertJsonPath('results.0.status', 'created');
        $this->assertDatabaseHas('attendance_records', [
            'attendee_id' => $attendee->id,
            'method' => 'manual',
        ]);
    }

    public function test_sync_batch_rejects_the_removed_event_registration_id(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);
        $attendee = $this->registeredAttendee($org, $event);

        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [
                ['client_ref' => 'old', 'event_id' => $event->id, 'session_id' => $session->id, 'event_registration_id' => 4242, 'method' => 'manual'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['scans.0.attendee_id']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_sync_batch_rejects_an_attendee_id_from_another_event(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);
        $foreign = $this->registeredAttendee($org, Event::factory()->for($org)->create());

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [
                ['client_ref' => 'x', 'event_id' => $event->id, 'session_id' => $session->id, 'attendee_id' => $foreign->id, 'method' => 'manual'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('results.0.status', 'error');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_sync_batch_mixed_results(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);

        $reg1 = $this->registeredAttendee($org, $event);
        $reg2 = $this->registeredAttendee($org, $event);
        $reg3 = $this->registeredAttendee($org, $event);

        // Pre-mark reg2 as already checked in.
        $reg2->attendanceRecords()->create([
            'event_session_id' => $session->id,
            'check_in_at' => now(),
            'method' => 'qr',
            'recorded_by' => $checker->id,
        ]);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [
                [
                    'client_ref' => 'ref-001',
                    'event_id' => $event->id,
                    'session_id' => $session->id,
                    'qr_token' => $reg1->qr_token,
                    'method' => 'qr',
                ],
                [
                    'client_ref' => 'ref-002',
                    'event_id' => $event->id,
                    'session_id' => $session->id,
                    'qr_token' => $reg2->qr_token,
                    'method' => 'qr',
                ],
                [
                    'client_ref' => 'ref-003',
                    'event_id' => $event->id,
                    'session_id' => $session->id,
                    'qr_token' => $reg3->qr_token,
                    'method' => 'qr',
                ],
            ],
        ]);

        $response->assertOk();
        $results = $response->json('results');

        $this->assertCount(3, $results);
        $this->assertEquals('created', $results[0]['status']);
        $this->assertEquals('duplicate', $results[1]['status']);
        $this->assertEquals('created', $results[2]['status']);

        // Verify only 2 records were created (the duplicate shouldn't create a new one).
        $this->assertDatabaseCount('attendance_records', 3);
    }

    public function test_sync_batch_unauthenticated_access_denied(): void
    {
        $response = $this->postJson('/api/v1/attendance/sync-batch', [
            'scans' => [
                [
                    'client_ref' => 'ref-001',
                    'event_id' => 1,
                    'session_id' => 1,
                    'qr_token' => 'token-123',
                    'method' => 'qr',
                ],
            ],
        ]);

        $response->assertUnauthorized();
    }
}
