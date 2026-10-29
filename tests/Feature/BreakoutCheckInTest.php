<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BreakoutCheckInTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private Breakout $breakout;

    private User $checker;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-25 09:10:00');
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create(['status' => 'published', 'check_in_window_minutes' => 30]);
        $this->breakout = $this->breakout('09:00', '10:00');
        $this->checker = User::factory()->checker()->for($this->org)->create();
        $this->admin = User::factory()->orgAdmin()->for($this->org)->create();
    }

    private function breakout(string $start, string $end, string $date = '2026-06-25'): Breakout
    {
        return Breakout::factory()->for($this->event)->create(['date' => $date, 'start_time' => $start, 'end_time' => $end]);
    }

    private function makeSession(string $start, string $end, string $date = '2026-06-25'): EventSession
    {
        return EventSession::factory()->for($this->event)->create(['session_date' => $date, 'start_time' => $start, 'end_time' => $end]);
    }

    private function attendee(bool $picked = true, ?Event $event = null): Attendee
    {
        $attendee = Attendee::factory()->for($event ?? $this->event)->for($this->org)->create();
        if ($picked) {
            $attendee->breakouts()->attach($this->breakout->id);
        }

        return $attendee;
    }

    private function scan(User $user, Attendee $attendee, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $this->event->id,
            'breakout_id' => $this->breakout->id,
            'qr_token' => $attendee->qr_token,
        ] + $extra);
    }

    public function test_checker_checks_in_a_pick_holder_by_qr_and_manually(): void
    {
        $qr = $this->attendee();
        $manual = $this->attendee();

        $this->scan($this->checker, $qr)->assertCreated()->assertJsonPath('data.breakout_id', $this->breakout->id)->assertJsonPath('data.is_walk_in', false);
        $this->actingAs($this->checker, 'sanctum')->postJson('/api/v1/attendance/manual', [
            'event_id' => $this->event->id,
            'breakout_id' => $this->breakout->id,
            'attendee_id' => $manual->id,
        ])->assertCreated();

        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $qr->id, 'breakout_id' => $this->breakout->id, 'event_session_id' => null, 'method' => 'qr']);
        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $manual->id, 'method' => 'manual']);
        $this->assertSame(1, AuditLog::where('action', 'attendance.checked_in')->where('auditable_id', AttendanceRecord::where('attendee_id', $qr->id)->value('id'))->count());
    }

    public function test_second_scan_is_a_duplicate_and_a_slot_must_be_exactly_one(): void
    {
        $attendee = $this->attendee();
        $this->scan($this->checker, $attendee)->assertCreated();
        $this->scan($this->checker, $attendee)->assertUnprocessable()->assertJsonValidationErrors('qr_token');

        $this->scan($this->checker, $attendee, ['session_id' => $this->makeSession('09:00', '10:00')->id])->assertUnprocessable();
        $this->actingAs($this->checker, 'sanctum')->postJson('/api/v1/attendance/scan', ['event_id' => $this->event->id, 'qr_token' => $attendee->qr_token])->assertUnprocessable();
    }

    public function test_draft_attendee_is_refused(): void
    {
        $draft = $this->attendee();
        Attendee::whereKey($draft->id)->update(['first_registered_at' => null]);

        $this->scan($this->admin, $draft)->assertUnprocessable();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_qr_from_another_event_is_always_rejected(): void
    {
        $other = Event::factory()->for($this->org)->create();
        $foreign = $this->attendee(false, $other);

        $this->scan($this->admin, $foreign, ['walk_in' => true])->assertUnprocessable()->assertJsonValidationErrors('qr_token');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_early_scan_needs_an_admin_override(): void
    {
        Carbon::setTestNow('2026-06-25 08:00:00');
        $attendee = $this->attendee();

        $this->scan($this->checker, $attendee, ['override' => true])->assertUnprocessable();
        $this->scan($this->admin, $attendee)->assertUnprocessable();
        $this->scan($this->admin, $attendee, ['override' => true])->assertCreated();
        $this->assertSame(1, AuditLog::where('action', 'attendance.checked_in_override')->count());
    }

    public function test_checker_scan_of_an_attendee_without_a_pick_is_refused(): void
    {
        $attendee = $this->attendee(false);

        $this->scan($this->checker, $attendee, ['walk_in' => true])->assertUnprocessable()->assertJsonValidationErrors('attendee_id');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_admin_walk_in_needs_the_flag_then_is_recorded_and_audited(): void
    {
        $attendee = $this->attendee(false);

        $this->scan($this->admin, $attendee)->assertUnprocessable()->assertJsonValidationErrors('attendee_id');
        $this->scan($this->admin, $attendee, ['walk_in' => true])->assertCreated()->assertJsonPath('data.is_walk_in', true);

        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $attendee->id, 'breakout_id' => $this->breakout->id, 'is_walk_in' => true, 'recorded_by' => $this->admin->id]);
        $this->assertSame(1, AuditLog::where('action', 'attendance.walk_in')->count());
        $this->assertDatabaseCount('breakout_picks', 0);
        $this->assertFalse($this->event->attendees()->expectedAt($this->breakout)->whereKey($attendee->id)->exists());
        $this->assertTrue($this->event->attendees()->presentAt($this->breakout)->whereKey($attendee->id)->exists());
    }

    public function test_walk_in_flag_does_not_mark_a_pick_holder_as_walk_in(): void
    {
        $this->scan($this->admin, $this->attendee(), ['walk_in' => true])->assertCreated()->assertJsonPath('data.is_walk_in', false);
    }

    public function test_checker_scan_after_the_breakout_ended_is_refused_and_admin_late_check_in_is_audited(): void
    {
        Carbon::setTestNow('2026-06-25 10:30:00');
        $attendee = $this->attendee();

        $this->scan($this->checker, $attendee, ['late' => true])->assertUnprocessable();
        $this->scan($this->admin, $attendee)->assertUnprocessable();
        $this->assertDatabaseCount('attendance_records', 0);

        $this->scan($this->admin, $attendee, ['late' => true])->assertCreated();
        $this->assertSame(1, AuditLog::where('action', 'attendance.checked_in_late')->count());
    }

    public function test_session_check_in_after_the_session_ended_follows_the_same_rule(): void
    {
        Carbon::setTestNow('2026-06-25 12:30:00');
        $session = $this->makeSession('09:00', '12:00');
        $attendee = $this->attendee(false);
        $body = ['event_id' => $this->event->id, 'session_id' => $session->id, 'qr_token' => $attendee->qr_token];

        $this->actingAs($this->checker, 'sanctum')->postJson('/api/v1/attendance/scan', $body + ['late' => true])->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendance/scan', $body + ['late' => true])->assertCreated();
        $this->assertSame(1, AuditLog::where('action', 'attendance.checked_in_late')->count());
    }

    public function test_breakout_check_in_also_checks_in_overlapping_same_date_sessions(): void
    {
        $overlap = $this->makeSession('08:00', '09:30');
        $inside = $this->makeSession('09:30', '12:00');
        $touching = $this->makeSession('10:00', '12:00');
        $before = $this->makeSession('08:00', '09:00');
        $otherDay = $this->makeSession('09:00', '10:00', '2026-06-26');
        $attendee = $this->attendee();

        $this->scan($this->checker, $attendee)->assertCreated();

        foreach ([$overlap, $inside] as $session) {
            $this->assertDatabaseHas('attendance_records', ['attendee_id' => $attendee->id, 'event_session_id' => $session->id, 'via_breakout_id' => $this->breakout->id, 'method' => 'qr', 'recorded_by' => $this->checker->id]);
        }
        foreach ([$touching, $before, $otherDay] as $session) {
            $this->assertDatabaseMissing('attendance_records', ['attendee_id' => $attendee->id, 'event_session_id' => $session->id]);
        }
        $this->assertSame(2, AuditLog::where('action', 'attendance.checked_in_via_breakout')->count());
        $this->assertTrue($this->event->attendees()->presentAt($overlap)->whereKey($attendee->id)->exists());
    }

    public function test_via_breakout_skips_sessions_the_actor_could_not_check_in_to(): void
    {
        $ended = $this->makeSession('08:00', '09:05');
        $notOpen = $this->makeSession('09:50', '12:00'); // opens 09:20
        $open = $this->makeSession('09:30', '12:00'); // opens 09:00
        $attendee = $this->attendee();

        $this->scan($this->checker, $attendee)->assertCreated();

        $this->assertDatabaseMissing('attendance_records', ['event_session_id' => $ended->id]);
        $this->assertDatabaseMissing('attendance_records', ['event_session_id' => $notOpen->id]);
        $this->assertDatabaseHas('attendance_records', ['event_session_id' => $open->id, 'attendee_id' => $attendee->id]);

        $admin = $this->attendee();
        $this->scan($this->admin, $admin, ['override' => true])->assertCreated();
        $this->assertDatabaseHas('attendance_records', ['event_session_id' => $notOpen->id, 'attendee_id' => $admin->id]);
        $this->assertDatabaseMissing('attendance_records', ['event_session_id' => $ended->id, 'attendee_id' => $admin->id]);
    }

    public function test_earliest_check_in_wins_and_session_check_in_never_creates_breakout_check_ins(): void
    {
        $session = $this->makeSession('09:00', '12:00');
        $attendee = $this->attendee();
        $earlier = AttendanceRecord::factory()->create(['attendee_id' => $attendee->id, 'event_session_id' => $session->id, 'check_in_at' => '2026-06-25 09:01:00', 'recorded_by' => $this->admin->id]);

        $this->scan($this->checker, $attendee)->assertCreated();

        $this->assertNull($earlier->fresh()->via_breakout_id);
        $this->assertEquals('2026-06-25 09:01:00', $earlier->fresh()->check_in_at->toDateTimeString());

        $other = $this->attendee();
        $this->actingAs($this->checker, 'sanctum')->postJson('/api/v1/attendance/scan', ['event_id' => $this->event->id, 'session_id' => $session->id, 'qr_token' => $other->qr_token])->assertCreated();
        $this->assertDatabaseMissing('attendance_records', ['attendee_id' => $other->id, 'breakout_id' => $this->breakout->id]);
    }

    public function test_walk_in_also_checks_in_overlapping_sessions(): void
    {
        $session = $this->makeSession('09:00', '12:00');
        $attendee = $this->attendee(false);

        $this->scan($this->admin, $attendee, ['walk_in' => true])->assertCreated();

        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $attendee->id, 'event_session_id' => $session->id, 'via_breakout_id' => $this->breakout->id, 'is_walk_in' => false]);
    }

    public function test_breakout_records_have_no_check_out(): void
    {
        $this->event->update(['requires_check_out' => true]);
        $record = AttendanceRecord::factory()->create(['attendee_id' => $this->attendee()->id, 'event_session_id' => null, 'breakout_id' => $this->breakout->id]);

        $this->actingAs($this->checker, 'sanctum')->postJson("/api/v1/attendance/{$record->id}/check-out")->assertUnprocessable();
    }

    public function test_a_record_is_unique_per_attendee_and_breakout(): void
    {
        $attendee = $this->attendee();
        AttendanceRecord::factory()->create(['attendee_id' => $attendee->id, 'event_session_id' => null, 'breakout_id' => $this->breakout->id]);

        $this->expectException(QueryException::class);
        AttendanceRecord::factory()->create(['attendee_id' => $attendee->id, 'event_session_id' => null, 'breakout_id' => $this->breakout->id]);
    }

    public function test_sync_batch_breakout_scan_matches_a_live_scan(): void
    {
        $session = $this->makeSession('09:00', '12:00');
        $pick = $this->attendee();
        $stranger = $this->attendee(false);

        $response = $this->actingAs($this->checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', ['scans' => [
            ['client_ref' => 'a', 'event_id' => $this->event->id, 'breakout_id' => $this->breakout->id, 'qr_token' => $pick->qr_token, 'method' => 'qr'],
            ['client_ref' => 'b', 'event_id' => $this->event->id, 'breakout_id' => $this->breakout->id, 'qr_token' => $stranger->qr_token, 'method' => 'qr'],
            ['client_ref' => 'c', 'event_id' => $this->event->id, 'breakout_id' => $this->breakout->id, 'qr_token' => $pick->qr_token, 'method' => 'qr'],
        ]]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.1.status', 'error')
            ->assertJsonPath('results.2.status', 'duplicate');
        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $pick->id, 'breakout_id' => $this->breakout->id]);
        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $pick->id, 'event_session_id' => $session->id, 'via_breakout_id' => $this->breakout->id]);
        $this->assertDatabaseMissing('attendance_records', ['attendee_id' => $stranger->id]);
    }

    public function test_sync_batch_uses_the_scans_client_time_for_late_and_walk_in_rules(): void
    {
        Carbon::setTestNow('2026-06-25 14:00:00');
        $pick = $this->attendee();
        $walkIn = $this->attendee(false);
        $late = $this->attendee();
        $scan = fn (Attendee $a, array $extra = []) => ['client_ref' => (string) $a->id, 'event_id' => $this->event->id, 'breakout_id' => $this->breakout->id, 'qr_token' => $a->qr_token, 'method' => 'qr'] + $extra;

        $checker = $this->actingAs($this->checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', ['scans' => [
            $scan($pick, ['scanned_at' => '2026-06-25 09:20:00']),
            $scan($late),
        ]]);
        $checker->assertJsonPath('results.0.status', 'created')->assertJsonPath('results.1.status', 'error');
        $this->assertEquals('2026-06-25 09:20:00', AttendanceRecord::where('attendee_id', $pick->id)->value('check_in_at'));

        $admin = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendance/sync-batch', ['scans' => [
            $scan($walkIn, ['scanned_at' => '2026-06-25 09:30:00', 'walk_in' => true]),
            $scan($late, ['late' => true]),
        ]]);
        $admin->assertJsonPath('results.0.status', 'created')->assertJsonPath('results.1.status', 'created');
        $this->assertSame(1, AuditLog::where('action', 'attendance.walk_in')->count());
        $this->assertSame(1, AuditLog::where('action', 'attendance.checked_in_late')->count());
    }

    public function test_breakout_records_feed_the_shared_present_absent_upcoming_rule(): void
    {
        $present = $this->attendee();
        $absent = $this->attendee();
        $this->attendee(false)->breakouts()->count();
        $this->scan($this->checker, $present)->assertCreated();

        $this->assertSame(AttendanceStatus::Present, $this->breakout->attendanceStatus(true, $present->first_registered_at));
        $this->assertSame(AttendanceStatus::Upcoming, $this->breakout->attendanceStatus(false, $absent->first_registered_at));

        Carbon::setTestNow('2026-06-25 10:30:00');
        $this->assertSame(['present' => 1, 'absent' => 1, 'upcoming' => 0, 'rate' => 50.0], $this->breakout->fresh()->attendanceCounts($this->event));
    }

    public function test_checker_without_access_to_the_event_cannot_check_in(): void
    {
        $outsider = User::factory()->checker()->for(Organization::factory()->create())->create();

        $this->scan($outsider, $this->attendee())->assertForbidden();
    }

    private function attendanceOff(): void
    {
        $this->breakout->update(['attendance_enabled' => false]);
        $this->makeSession('09:00', '12:00');
    }

    public function test_scan_into_a_breakout_with_attendance_off_is_refused(): void
    {
        $this->attendanceOff();

        $this->scan($this->checker, $this->attendee())->assertUnprocessable()->assertJsonValidationErrors('breakout_id');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_manual_and_walk_in_check_in_to_a_breakout_with_attendance_off_are_refused(): void
    {
        $this->attendanceOff();
        $pick = $this->attendee();
        $stranger = $this->attendee(false);

        $this->actingAs($this->checker, 'sanctum')->postJson('/api/v1/attendance/manual', [
            'event_id' => $this->event->id, 'breakout_id' => $this->breakout->id, 'attendee_id' => $pick->id,
        ])->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/attendance/manual', [
            'event_id' => $this->event->id, 'breakout_id' => $this->breakout->id, 'attendee_id' => $stranger->id, 'walk_in' => true,
        ])->assertUnprocessable();
        $this->scan($this->admin, $stranger, ['walk_in' => true])->assertUnprocessable();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_test_mode_scan_for_a_breakout_with_attendance_off_is_refused(): void
    {
        $this->attendanceOff();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->scan($superAdmin, $this->attendee(), ['test_mode' => true])->assertUnprocessable()->assertJsonValidationErrors('breakout_id');
    }

    public function test_sync_batch_rejects_only_the_scan_for_a_breakout_with_attendance_off(): void
    {
        $off = $this->breakout('13:00', '14:00');
        $off->update(['attendance_enabled' => false]);
        $pick = $this->attendee();
        $this->makeSession('09:00', '12:00');

        $response = $this->actingAs($this->checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', ['scans' => [
            ['client_ref' => 'off', 'event_id' => $this->event->id, 'breakout_id' => $off->id, 'qr_token' => $pick->qr_token, 'method' => 'qr'],
            ['client_ref' => 'on', 'event_id' => $this->event->id, 'breakout_id' => $this->breakout->id, 'qr_token' => $pick->qr_token, 'method' => 'qr'],
        ]]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.1.status', 'created');
        $this->assertStringContainsString('attendance', strtolower($response->json('results.0.message')));
        $this->assertDatabaseMissing('attendance_records', ['breakout_id' => $off->id]);
        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $pick->id, 'breakout_id' => $this->breakout->id]);
    }
}
