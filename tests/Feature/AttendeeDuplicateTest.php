<?php

namespace Tests\Feature;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AttendeeDuplicateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creating_attendee_with_duplicate_name_returns_conflict(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $existing = Attendee::factory()->for($event)->create(['first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz']);
        DB::table('attendees')->where('id', $existing->id)->update(['normalized_name' => 'stale value']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees', [
            'event_id' => $event->id,
            'first_name' => 'juan',
            'last_name' => '  dela   cruz ',
            'organization_level' => 'union',
            'union_id' => $union->id,
        ]);

        $response->assertStatus(409);
        $this->assertSame(1, Attendee::count());
    }

    public function test_creating_attendee_with_override_bypasses_duplicate_check(): void
    {
        Queue::fake();

        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        Attendee::factory()->for($event)->create(['first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees', [
            'event_id' => $event->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'organization_level' => 'union',
            'union_id' => $union->id,
            'confirm' => true,
        ]);

        $response->assertCreated();
        $this->assertSame(2, Attendee::count());
    }

    public function test_check_duplicates_endpoint_finds_matches_without_creating(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        Attendee::factory()->for($org)->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']);

        $response = $this->actingAs($checker, 'sanctum')->getJson('/api/v1/attendees/check-duplicates?'.http_build_query([
            'first_name' => 'ana',
            'last_name' => 'reyes',
        ]));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(1, Attendee::count());
    }

    public function test_different_middle_names_still_match_and_can_be_merged(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $first = Attendee::factory()->for($org)->create([
            'first_name' => 'Maria', 'middle_name' => 'Lopez', 'last_name' => 'Santos',
        ]);
        $second = Attendee::factory()->for($org)->create([
            'first_name' => 'maria', 'middle_name' => 'Reyes', 'last_name' => 'santos',
        ]);
        DB::table('attendees')->where('id', $first->id)->update(['normalized_name' => 'maria lopez santos']);
        DB::table('attendees')->where('id', $second->id)->update(['normalized_name' => 'maria reyes santos']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/attendees/check-duplicates?'.http_build_query([
            'first_name' => 'Maria', 'middle_name' => 'Garcia', 'last_name' => 'Santos',
        ]))->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/attendees/duplicates')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees/duplicates/merge', [
            'primary_attendee_id' => $first->id,
            'duplicate_attendee_ids' => [$second->id],
        ])->assertOk();
    }

    public function test_duplicate_dismissals_ignore_stale_name_keys(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $first = Attendee::factory()->for($org)->create([
            'first_name' => 'Maria', 'middle_name' => 'Lopez', 'last_name' => 'Santos',
        ]);
        $second = Attendee::factory()->for($org)->create([
            'first_name' => 'Maria', 'middle_name' => 'Lopez', 'last_name' => 'Santos',
        ]);
        DB::table('attendees')->whereIn('id', [$first->id, $second->id])
            ->update(['normalized_name' => 'maria lopez santos']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees/duplicates/dismiss', [
            'attendee_ids' => [$first->id, $second->id],
        ])->assertNoContent();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/attendees/duplicates')
            ->assertOk()->assertJsonPath('meta.total', 0);

        $migration = require database_path('migrations/2026_09_25_000000_exclude_middle_name_from_attendee_duplicate_key.php');
        $migration->up();

        $this->assertDatabaseHas('attendees', ['id' => $first->id, 'normalized_name' => 'maria santos']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/attendees/duplicates')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_admin_can_review_dismiss_and_reopen_duplicate_group(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $first = Attendee::factory()->for($org)->create(['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos']);
        $second = Attendee::factory()->for($org)->create(['first_name' => 'maria', 'middle_name' => null, 'last_name' => '  santos ']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/attendees/duplicates')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(2, 'data.0.attendees');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees/duplicates/dismiss', [
            'attendee_ids' => [$first->id, $second->id],
        ])->assertNoContent();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/attendees/duplicates')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        Attendee::factory()->for($org)->create(['first_name' => 'Maria', 'middle_name' => null, 'last_name' => 'Santos']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/attendees/duplicates')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_merge_fills_blank_primary_fields_regenerates_the_id_card_and_archives_source(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $primary = Attendee::factory()->for($event)->create([
            'first_name' => 'Jose', 'middle_name' => null, 'last_name' => 'Rizal', 'mobile_no' => null, 'email_address' => null,
        ]);
        $source = Attendee::factory()->for($event)->create([
            'first_name' => 'jose', 'middle_name' => null, 'last_name' => ' rizal ', 'mobile_no' => '09171234567', 'email_address' => 'jose.rizal@example.test',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees/duplicates/merge', [
            'primary_attendee_id' => $primary->id,
            'duplicate_attendee_ids' => [$source->id],
        ])->assertOk()->assertJsonPath('data.id', $primary->id);

        Queue::assertPushed(
            GenerateAttendeeIdCardJob::class,
            fn (GenerateAttendeeIdCardJob $job): bool => $job->attendee->is($primary)
        );

        $this->assertDatabaseHas('attendees', [
            'id' => $primary->id,
            'mobile_no' => '09171234567',
            'email_address' => 'jose.rizal@example.test',
        ]);
        $this->assertDatabaseHas('attendees', ['id' => $source->id, 'merged_into_id' => $primary->id]);

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/attendees/{$source->id}?include=dashboard")
            ->assertOk()
            ->assertJsonPath('data.id', $primary->id)
            ->assertJsonPath('merged_from_id', $source->id)
            ->assertJsonPath('profile_dashboard.stats.registration_count', 1)
            ->assertJsonPath('profile_dashboard.registrations.0.id', $primary->id);
    }

    public function test_name_changing_update_warns_and_override_allows_it(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        Attendee::factory()->for($org)->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']);
        $attendee = Attendee::factory()->for($org)->create(['first_name' => 'Bea', 'middle_name' => null, 'last_name' => 'Cruz']);
        DB::table('attendees')->where('first_name', 'Ana')->update(['normalized_name' => 'stale value']);
        DB::table('attendees')->where('id', $attendee->id)->update(['normalized_name' => 'stale value']);

        $payload = ['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes'];
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", $payload)
            ->assertStatus(409)
            ->assertJsonStructure(['duplicates' => [['id']]]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", $payload + ['override_duplicate' => true])
            ->assertOk();
    }

    public function test_duplicate_attendance_count_ignores_records_of_breakouts_with_attendance_off(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $first = Attendee::factory()->for($event)->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Cruz']);
        Attendee::factory()->for($event)->create(['first_name' => 'ana', 'middle_name' => null, 'last_name' => 'cruz']);
        $session = EventSession::factory()->for($event)->create();
        $hidden = Breakout::factory()->for($event)->create(['attendance_enabled' => false]);
        AttendanceRecord::factory()->for($first)->for($session)->create(['check_in_at' => now()]);
        AttendanceRecord::factory()->for($first)->create(['breakout_id' => $hidden->id, 'event_session_id' => null, 'check_in_at' => now()]);

        $counts = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/attendees/duplicates')
            ->assertOk()->json('data.0.attendees'))->pluck('attendance_count', 'id');

        $this->assertSame(1, $counts[$first->id]);
    }

    public function test_merge_unions_same_event_attendance_records(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $primary = Attendee::factory()->for($event)->create(['first_name' => 'Luz', 'middle_name' => null, 'last_name' => 'Viminda']);
        $source = Attendee::factory()->for($event)->create(['first_name' => 'luz', 'middle_name' => null, 'last_name' => ' viminda ']);
        $session = EventSession::factory()->for($event)->create();
        $earliestCheckIn = now()->subHour();
        $latestCheckOut = now()->addHour();
        AttendanceRecord::factory()->for($primary)->for($session)->create([
            'check_in_at' => $earliestCheckIn,
            'check_out_at' => now(),
        ]);
        AttendanceRecord::factory()->for($source)->for($session)->create([
            'check_in_at' => now()->subMinutes(30),
            'check_out_at' => $latestCheckOut,
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees/duplicates/merge', [
            'primary_attendee_id' => $primary->id,
            'duplicate_attendee_ids' => [$source->id],
        ])->assertOk();

        $this->assertDatabaseHas('attendees', ['id' => $source->id, 'merged_into_id' => $primary->id]);
        $this->assertDatabaseCount('attendance_records', 1);
        $record = AttendanceRecord::where('attendee_id', $primary->id)
            ->where('event_session_id', $session->id)
            ->sole();
        $this->assertSame($earliestCheckIn->format('Y-m-d H:i:s'), $record->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame($latestCheckOut->format('Y-m-d H:i:s'), $record->check_out_at->format('Y-m-d H:i:s'));
    }

    public function test_duplicate_candidates_never_span_events(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $eventA = Event::factory()->for($org)->create();
        $eventB = Event::factory()->for($org)->create();
        Attendee::factory()->for($eventA)->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Cruz']);
        Attendee::factory()->for($eventB)->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Cruz']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/attendees/duplicates')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        Attendee::factory()->for($eventA)->create(['first_name' => 'ana', 'middle_name' => null, 'last_name' => 'cruz']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/attendees/duplicates')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(2, 'data.0.attendees');
        $this->assertSame([$eventA->id], collect($response->json('data.0.attendees'))->pluck('event_id')->unique()->all());
    }

    public function test_dismiss_and_merge_reject_duplicates_from_different_events(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $first = Attendee::factory()->for(Event::factory()->for($org)->create())->create(['first_name' => 'Ben', 'middle_name' => null, 'last_name' => 'Reyes']);
        $second = Attendee::factory()->for(Event::factory()->for($org)->create())->create(['first_name' => 'Ben', 'middle_name' => null, 'last_name' => 'Reyes']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees/duplicates/dismiss', [
            'attendee_ids' => [$first->id, $second->id],
        ])->assertUnprocessable();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees/duplicates/merge', [
            'primary_attendee_id' => $first->id,
            'duplicate_attendee_ids' => [$second->id],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('attendees', ['id' => $second->id, 'merged_into_id' => null]);
    }

    public function test_merge_clears_the_sources_user_and_email(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $account = User::factory()->attendee()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $primary = Attendee::factory()->for($event)->create(['first_name' => 'Eva', 'middle_name' => null, 'last_name' => 'Lim', 'email_address' => null]);
        $source = Attendee::factory()->for($event)->create([
            'first_name' => 'Eva', 'middle_name' => null, 'last_name' => 'Lim', 'email_address' => 'eva@example.test', 'user_id' => $account->id,
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees/duplicates/merge', [
            'primary_attendee_id' => $primary->id,
            'duplicate_attendee_ids' => [$source->id],
        ])->assertOk();

        $this->assertDatabaseHas('attendees', ['id' => $source->id, 'user_id' => null, 'email_address' => null, 'merged_into_id' => $primary->id]);
        $this->assertDatabaseHas('attendees', ['id' => $primary->id, 'user_id' => $account->id, 'email_address' => 'eva@example.test']);
    }

    public function test_checker_cannot_review_duplicate_groups(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/attendees/duplicates')->assertForbidden();
    }
}
