<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\AttendanceRecord;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AttendeeDuplicateTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creating_attendee_with_duplicate_name_returns_conflict(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        Attendee::factory()->for($org)->create(['first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz']);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
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
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $union = Union::factory()->for($org)->create();
        Attendee::factory()->for($org)->create(['first_name' => 'Juan', 'middle_name' => null, 'last_name' => 'Dela Cruz']);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'organization_level' => 'union',
            'union_id' => $union->id,
            'override_duplicate' => true,
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

    public function test_merge_transfers_registration_fills_blank_primary_fields_and_archives_source(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $primary = Attendee::factory()->for($org)->create([
            'first_name' => 'Jose', 'middle_name' => null, 'last_name' => 'Rizal', 'mobile_no' => null, 'email_address' => null,
        ]);
        $source = Attendee::factory()->for($org)->create([
            'first_name' => 'jose', 'middle_name' => null, 'last_name' => ' rizal ', 'mobile_no' => '09171234567', 'email_address' => 'jose.rizal@example.test',
        ]);
        $event = Event::factory()->for($org)->create();
        $registration = EventRegistration::factory()->for($event)->for($source)->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees/duplicates/merge', [
            'primary_attendee_id' => $primary->id,
            'duplicate_attendee_ids' => [$source->id],
        ])->assertOk()->assertJsonPath('data.id', $primary->id);

        $this->assertDatabaseHas('attendees', [
            'id' => $primary->id,
            'mobile_no' => '09171234567',
            'email_address' => 'jose.rizal@example.test',
        ]);
        $this->assertDatabaseHas('attendees', ['id' => $source->id, 'merged_into_id' => $primary->id]);
        $this->assertDatabaseHas('event_registrations', ['id' => $registration->id, 'attendee_id' => $primary->id]);

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/attendees/{$source->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $primary->id)
            ->assertJsonPath('merged_from_id', $source->id);
    }

    public function test_name_changing_update_warns_and_override_allows_it(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        Attendee::factory()->for($org)->create(['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes']);
        $attendee = Attendee::factory()->for($org)->create(['first_name' => 'Bea', 'middle_name' => null, 'last_name' => 'Cruz']);

        $payload = ['first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Reyes'];
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", $payload)
            ->assertStatus(409)
            ->assertJsonStructure(['duplicates' => [['id']]]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}", $payload + ['override_duplicate' => true])
            ->assertOk();
    }

    public function test_merge_unions_same_event_attendance_records(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $primary = Attendee::factory()->for($org)->create(['first_name' => 'Luz', 'middle_name' => null, 'last_name' => 'Viminda']);
        $source = Attendee::factory()->for($org)->create(['first_name' => 'luz', 'middle_name' => null, 'last_name' => ' viminda ']);
        $event = Event::factory()->for($org)->create();
        $session = EventSession::factory()->for($event)->create();
        $primaryRegistration = EventRegistration::factory()->for($event)->for($primary)->create();
        $sourceRegistration = EventRegistration::factory()->for($event)->for($source)->create();
        $earliestCheckIn = now()->subHour();
        $latestCheckOut = now()->addHour();
        AttendanceRecord::factory()->for($primaryRegistration, 'eventRegistration')->for($session)->create([
            'check_in_at' => $earliestCheckIn,
            'check_out_at' => now(),
        ]);
        AttendanceRecord::factory()->for($sourceRegistration, 'eventRegistration')->for($session)->create([
            'check_in_at' => now()->subMinutes(30),
            'check_out_at' => $latestCheckOut,
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendees/duplicates/merge', [
            'primary_attendee_id' => $primary->id,
            'duplicate_attendee_ids' => [$source->id],
        ])->assertOk();

        $this->assertDatabaseMissing('event_registrations', ['id' => $sourceRegistration->id]);
        $record = AttendanceRecord::where('event_registration_id', $primaryRegistration->id)
            ->where('event_session_id', $session->id)
            ->sole();
        $this->assertSame($earliestCheckIn->format('Y-m-d H:i:s'), $record->check_in_at->format('Y-m-d H:i:s'));
        $this->assertSame($latestCheckOut->format('Y-m-d H:i:s'), $record->check_out_at->format('Y-m-d H:i:s'));
    }

    public function test_checker_cannot_review_duplicate_groups(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $this->actingAs($checker, 'sanctum')->getJson('/api/v1/attendees/duplicates')->assertForbidden();
    }
}
