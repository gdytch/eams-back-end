<?php

namespace Tests\Feature;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Jobs\RecomputeEventCompletenessJob;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use App\Services\RegistrationCompleteness;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RegistrationCompletenessTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function openSession(Event $event): EventSession
    {
        return EventSession::factory()->for($event)->create([
            'session_date' => now()->toDateString(),
            'start_time' => now()->format('H:i:s'),
            'end_time' => now()->addHours(4)->format('H:i:s'),
        ]);
    }

    /** A mission-level Attendee that was registered under the old rule: no church. */
    private function missionWithoutChurch(Event $event): Attendee
    {
        $union = Union::factory()->create(['organization_id' => $event->organization_id]);
        $mission = Mission::factory()->create(['organization_id' => $event->organization_id, 'union_id' => $union->id]);
        $attendee = Attendee::factory()->for($event)->create(['organization_level' => 'mission', 'union_id' => $union->id, 'mission_id' => $mission->id]);

        // Simulate the pre-release state: saved before the rule existed.
        Attendee::withoutEvents(fn () => $attendee->forceFill([
            'registration_confirmed_at' => now(), 'registration_completed_at' => now(), 'first_registered_at' => now(), 'registered_at' => now(), 'qr_token' => 'qr-'.$attendee->id,
        ])->save());

        return $attendee->refresh();
    }

    public function test_draft_has_no_qr_token_and_complete_attendee_gets_one(): void
    {
        $event = Event::factory()->for(Organization::factory()->create())->create();

        $draft = Attendee::factory()->for($event)->draft()->create();
        $complete = Attendee::factory()->for($event)->create();

        $this->assertNull($draft->qr_token);
        $this->assertNull($draft->first_registered_at);
        $this->assertNotNull($complete->qr_token);
        $this->assertNotNull($complete->registration_completed_at);
        $this->assertNotNull($complete->first_registered_at);
        $this->assertSame(['confirmation'], app(RegistrationCompleteness::class)->steps($draft)['review']);
    }

    public function test_confirming_a_draft_registers_it(): void
    {
        $event = Event::factory()->for(Organization::factory()->create())->create();
        $draft = Attendee::factory()->for($event)->draft()->create();

        $draft->update(['registration_confirmed_at' => now()]);

        $this->assertNotNull($draft->refresh()->registration_completed_at);
        $this->assertNotNull($draft->qr_token);
    }

    public function test_becoming_incomplete_clears_completed_and_confirmation_but_keeps_first_registered(): void
    {
        $event = Event::factory()->for(Organization::factory()->create())->create();
        $attendee = Attendee::factory()->for($event)->create();
        $first = $attendee->first_registered_at;
        $qr = $attendee->qr_token;

        $attendee->update(['mobile_no' => null]);

        $attendee->refresh();
        $this->assertNull($attendee->registration_completed_at);
        $this->assertNull($attendee->registration_confirmed_at);
        $this->assertEquals($first, $attendee->first_registered_at);
        $this->assertSame($qr, $attendee->qr_token);

        // Fixing the data alone does not re-register: the Finish confirmation is gone.
        $attendee->update(['mobile_no' => '09171234567']);
        $this->assertNull($attendee->refresh()->registration_completed_at);
        $this->assertEquals($first, $attendee->first_registered_at);
    }

    public function test_per_step_completeness_is_in_the_attendee_resource(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $attendee = Attendee::factory()->for($event)->draft()->create(['mobile_no' => null, 'organization_level' => null, 'union_id' => null]);

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/attendees/{$attendee->id}")
            ->assertOk()
            ->assertJsonPath('data.registration_status', 'draft')
            ->assertJsonPath('data.completeness.basic_info', ['complete' => false, 'missing' => ['mobile_no']])
            ->assertJsonPath('data.completeness.territory', ['complete' => false, 'missing' => ['organization_level']])
            ->assertJsonPath('data.completeness.review.complete', false);
    }

    public function test_recompute_command_makes_mission_level_without_church_incomplete_and_keeps_union_level(): void
    {
        $event = Event::factory()->for(Organization::factory()->create())->create();
        $mission = $this->missionWithoutChurch($event);
        $union = Attendee::factory()->for($event)->create();

        $this->artisan('app:recompute-registration-completeness')->assertSuccessful();

        $this->assertNull($mission->refresh()->registration_completed_at);
        $this->assertNotNull($mission->first_registered_at);
        $this->assertNotNull($union->refresh()->registration_completed_at);
    }

    public function test_recompute_command_skips_archived_and_past_events(): void
    {
        $org = Organization::factory()->create();
        $archived = Event::factory()->for($org)->create(['archived_at' => now()]);
        $past = Event::factory()->for($org)->create(['start_date' => now()->subDays(5), 'end_date' => now()->subDays(3)]);
        $a = $this->missionWithoutChurch($archived);
        $p = $this->missionWithoutChurch($past);

        $this->artisan('app:recompute-registration-completeness')->assertSuccessful();

        $this->assertNotNull($a->refresh()->registration_completed_at);
        $this->assertNotNull($p->refresh()->registration_completed_at);
    }

    public function test_dry_run_count_equals_the_attendees_the_job_makes_incomplete(): void
    {
        $event = Event::factory()->for(Organization::factory()->create())->create();
        $this->missionWithoutChurch($event);
        $this->missionWithoutChurch($event);
        Attendee::factory()->for($event)->create();
        $service = app(RegistrationCompleteness::class);

        $count = $service->countBecomingIncomplete($event);
        $this->artisan('app:recompute-registration-completeness --dry-run')->expectsOutput('This makes 2 registrants incomplete.')->assertSuccessful();

        (new RecomputeEventCompletenessJob($event))->handle($service);

        $this->assertSame(2, $count);
        $this->assertSame(1, $event->attendees()->whereNotNull('registration_completed_at')->count());
    }

    public function test_recompute_job_runs_from_the_queue(): void
    {
        Queue::fake();
        $event = Event::factory()->for(Organization::factory()->create())->create();
        $this->missionWithoutChurch($event);

        RecomputeEventCompletenessJob::dispatch($event);
        Queue::assertPushed(RecomputeEventCompletenessJob::class, fn ($job) => $job->event->is($event));

        (new RecomputeEventCompletenessJob($event))->handle(app(RegistrationCompleteness::class));
        $this->assertSame(0, $event->attendees()->whereNotNull('registration_completed_at')->count());
    }

    public function test_scan_by_attendee_id_refuses_a_draft(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = $this->openSession($event);
        $draft = Attendee::factory()->for($event)->draft()->create();

        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/manual', [
            'event_id' => $event->id, 'session_id' => $session->id, 'attendee_id' => $draft->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('attendee_id');

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_scan_admits_a_once_registered_incomplete_attendee_with_a_note(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = $this->openSession($event);
        $attendee = Attendee::factory()->for($event)->create();
        $attendee->update(['mobile_no' => null]);

        $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/scan', [
            'event_id' => $event->id, 'session_id' => $session->id, 'qr_token' => $attendee->qr_token,
        ])->assertCreated()->assertJsonPath('incomplete', true);

        $this->assertDatabaseHas('attendance_records', ['attendee_id' => $attendee->id]);
    }

    public function test_batch_sync_reports_the_incomplete_note_and_refuses_drafts(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $session = $this->openSession($event);
        $once = Attendee::factory()->for($event)->create();
        $once->update(['mobile_no' => null]);
        $draft = Attendee::factory()->for($event)->draft()->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendance/sync-batch', ['scans' => [
            ['client_ref' => 'a', 'event_id' => $event->id, 'session_id' => $session->id, 'qr_token' => $once->qr_token, 'method' => 'qr'],
            ['client_ref' => 'b', 'event_id' => $event->id, 'session_id' => $session->id, 'attendee_id' => $draft->id, 'method' => 'manual'],
        ]])->assertOk();

        $response->assertJsonPath('results.0.status', 'created')->assertJsonPath('results.0.incomplete', true);
        $response->assertJsonPath('results.1.status', 'error')->assertJsonPath('results.1.message', 'Registration incomplete.');
    }

    public function test_id_card_job_skips_a_draft(): void
    {
        $event = Event::factory()->for(Organization::factory()->create())->create();
        $draft = Attendee::factory()->for($event)->draft()->create();

        (new GenerateAttendeeIdCardJob($draft))->handle();

        $this->assertNull($draft->refresh()->id_card_path);
    }

    public function test_migration_backfills_registered_attendees(): void
    {
        $event = Event::factory()->for(Organization::factory()->create())->create();
        $attendee = Attendee::factory()->for($event)->create();
        $at = now()->subDays(3)->startOfSecond();
        Attendee::withoutEvents(fn () => $attendee->forceFill([
            'registered_at' => $at, 'registration_confirmed_at' => null, 'registration_completed_at' => null, 'first_registered_at' => null,
        ])->save());

        $migration = require database_path('migrations/2026_10_22_200000_add_registration_completeness_to_attendees_table.php');
        $migration->down();
        $migration->up();

        $attendee->refresh();
        $this->assertEquals($at, $attendee->first_registered_at);
        $this->assertEquals($at, $attendee->registration_completed_at);
        $this->assertEquals($at, $attendee->registration_confirmed_at);
    }
}
