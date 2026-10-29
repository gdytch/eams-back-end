<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\EventProgram;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BreakoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create();
        $this->url = "/api/v1/events/{$this->event->id}/breakouts";
        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Youth Track',
            'date' => '2026-06-25',
            'start_time' => '09:00',
            'end_time' => '10:30',
            'room' => 'Hall B',
            'capacity' => 2,
            'topics' => ['Faith', 'Service'],
            'speaker_ids' => [],
            ...$overrides,
        ];
    }

    private function picks(Breakout $breakout, int $count): void
    {
        $breakout->picks()->attach(Attendee::factory()->for($this->org)->count($count)->create()->pluck('id'));
    }

    public function test_crud_with_ordered_speakers_and_topics(): void
    {
        [$a, $b] = Speaker::factory()->for($this->event)->count(2)->create();

        $id = $this->postJson($this->url, $this->payload(['speaker_ids' => [$b->id, $a->id]]))
            ->assertCreated()
            ->assertJsonPath('data.speakers.0.id', $b->id)
            ->assertJsonPath('data.topics', ['Faith', 'Service'])
            ->assertJsonPath('data.start_time', '09:00')
            ->json('data.id');
        $this->assertSame($this->org->id, Breakout::findOrFail($id)->organization_id);

        $this->putJson("$this->url/$id", $this->payload(['speaker_ids' => [$a->id], 'capacity' => null]))
            ->assertOk()->assertJsonPath('data.capacity', null)->assertJsonCount(1, 'data.speakers');
        $this->getJson("$this->url/$id")->assertOk()->assertJsonPath('data.room', 'Hall B');
        $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("$this->url/$id")->assertNoContent();
        $this->assertModelMissing(Breakout::findOrNew($id));
    }

    public function test_attendance_enabled_defaults_true_and_can_be_turned_off_and_on(): void
    {
        $id = $this->postJson($this->url, $this->payload())->assertCreated()
            ->assertJsonPath('data.attendance_enabled', true)->json('data.id');

        $this->putJson("$this->url/$id", $this->payload(['attendance_enabled' => false]))
            ->assertOk()->assertJsonPath('data.attendance_enabled', false);
        $this->getJson("$this->url/$id")->assertJsonPath('data.attendance_enabled', false);
        $this->getJson($this->url)->assertJsonPath('data.0.attendance_enabled', false);

        $this->putJson("$this->url/$id", $this->payload(['attendance_enabled' => true]))
            ->assertOk()->assertJsonPath('data.attendance_enabled', true);

        $this->postJson($this->url, $this->payload(['attendance_enabled' => false]))
            ->assertCreated()->assertJsonPath('data.attendance_enabled', false);
        $this->postJson($this->url, $this->payload(['attendance_enabled' => 'maybe']))
            ->assertUnprocessable()->assertJsonValidationErrors('attendance_enabled');
    }

    public function test_time_and_field_validation(): void
    {
        $this->postJson($this->url, $this->payload(['start_time' => '10:00', 'end_time' => '09:00']))->assertUnprocessable()->assertJsonValidationErrors('end_time');
        $this->postJson($this->url, $this->payload(['start_time' => '23:00', 'end_time' => '00:30']))->assertUnprocessable()->assertJsonValidationErrors('end_time');
        $this->postJson($this->url, $this->payload(['capacity' => 0]))->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->postJson($this->url, $this->payload(['topics' => []]))->assertUnprocessable()->assertJsonValidationErrors('topics');
        $this->postJson($this->url, $this->payload(['room' => '']))->assertUnprocessable()->assertJsonValidationErrors('room');
    }

    public function test_speaker_from_another_event_is_rejected(): void
    {
        $foreign = Speaker::factory()->for(Event::factory()->for($this->org)->create())->create();

        $this->postJson($this->url, $this->payload(['speaker_ids' => [$foreign->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('speaker_ids.0');
    }

    public function test_deleting_a_speaker_detaches_it(): void
    {
        $speaker = Speaker::factory()->for($this->event)->create();
        $breakout = Breakout::factory()->for($this->event)->create();
        $breakout->speakers()->attach($speaker);

        $speaker->delete();

        $this->assertSame(0, $breakout->speakers()->count());
        $this->assertModelExists($breakout);
    }

    public function test_archived_event_blocks_writes(): void
    {
        $breakout = Breakout::factory()->for($this->event)->create();
        $this->event->forceFill(['archived_at' => now()])->save();

        $this->postJson($this->url, $this->payload())->assertForbidden();
        $this->putJson("$this->url/$breakout->id", $this->payload())->assertForbidden();
        $this->deleteJson("$this->url/$breakout->id")->assertForbidden();
        $this->getJson($this->url)->assertOk();
    }

    public function test_other_roles_and_organizations_cannot_write(): void
    {
        $this->actingAs(User::factory()->checker()->for($this->org)->create(), 'sanctum');
        $this->postJson($this->url, $this->payload())->assertForbidden();

        $this->actingAs(User::factory()->orgAdmin()->for(Organization::factory()->create())->create(), 'sanctum');
        $this->postJson($this->url, $this->payload())->assertNotFound();
    }

    public function test_breakout_of_another_event_is_not_reachable_through_this_event(): void
    {
        $other = Breakout::factory()->for(Event::factory()->for($this->org)->create())->create();

        $this->getJson("$this->url/$other->id")->assertNotFound();
    }

    public function test_delete_is_blocked_while_picks_exist(): void
    {
        $breakout = Breakout::factory()->for($this->event)->create();
        $this->picks($breakout, 1);

        $this->deleteJson("$this->url/$breakout->id")->assertUnprocessable();
        $this->assertModelExists($breakout);
    }

    public function test_list_shows_picks_overbooked_flag_filter_and_dashboard_count(): void
    {
        $over = Breakout::factory()->for($this->event)->create(['capacity' => 1, 'title' => 'Over']);
        $full = Breakout::factory()->for($this->event)->create(['capacity' => 2]);
        $open = Breakout::factory()->for($this->event)->create(['capacity' => null]);
        $this->picks($over, 2);
        $this->picks($full, 2);
        $this->picks($open, 3);

        $rows = collect($this->getJson($this->url)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(2, $rows[$over->id]['picks_count']);
        $this->assertTrue($rows[$over->id]['overbooked']);
        $this->assertFalse($rows[$full->id]['overbooked']);
        $this->assertFalse($rows[$open->id]['overbooked']);

        $this->getJson("$this->url?overbooked=1")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Over');
        $this->getJson("/api/v1/events/{$this->event->id}/reports/dashboard")->assertOk()->assertJsonPath('overbooked_breakouts', 1);
    }

    public function test_breakout_program_has_one_day_following_the_breakout_date(): void
    {
        $breakout = Breakout::factory()->for($this->event)->create(['date' => '2026-06-25']);
        $program = "$this->url/$breakout->id/program";

        $this->postJson($program)->assertCreated()->assertJsonPath('data.days.0.date', '2026-06-25');
        $this->postJson($program)->assertStatus(409);
        $this->assertNull($this->event->program()->first());

        $section = $this->postJson("$program/builder/sections", ['parent_id' => $this->getJson($program)->json('data.days.0.id'), 'title' => 'Talk'])->assertCreated();
        $this->postJson("$program/builder/days", ['date' => '2026-06-26'])->assertNotFound();

        $this->putJson("$this->url/$breakout->id", $this->payload(['date' => '2026-06-26']))->assertOk();
        $this->getJson($program)->assertJsonPath('data.days.0.date', '2026-06-26');

        $this->deleteJson($program)->assertNoContent();
        $this->getJson($program)->assertNotFound();
        $this->assertSame(0, EventProgram::where('breakout_id', $breakout->id)->count());
    }

    public function test_event_program_is_still_independent_of_breakout_programs(): void
    {
        $breakout = Breakout::factory()->for($this->event)->create();
        $this->postJson("$this->url/$breakout->id/program")->assertCreated();

        $this->postJson("/api/v1/events/{$this->event->id}/program", [])->assertCreated();
        $this->assertNotSame($this->event->program()->first()->id, $breakout->program()->first()->id);
    }

    public function test_public_landing_shows_breakouts_only_when_event_program_is_public(): void
    {
        $speaker = Speaker::factory()->for($this->event)->create();
        $breakout = Breakout::factory()->for($this->event)->create(['title' => 'Youth Track']);
        $breakout->speakers()->attach($speaker);
        $program = EventProgram::factory()->for($this->event)->create(['is_public' => false]);
        $this->app['auth']->forgetGuards();
        $landing = "/api/v1/public/event/{$this->event->invite_token}";

        $this->getJson($landing)->assertOk()->assertJsonCount(0, 'data.breakouts');

        $program->update(['is_public' => true]);
        $this->getJson($landing)->assertOk()->assertJsonPath('data.breakouts.0.title', 'Youth Track')
            ->assertJsonMissingPath('data.breakouts.0.picks_count');
    }

    public function test_attendee_dashboard_shows_breakouts_only_when_event_program_is_public(): void
    {
        $user = User::factory()->attendee()->create(['organization_id' => $this->org->id]);
        $attendee = Attendee::factory()->for($this->org)->for($user)->create();
        $event = Event::factory()->for($this->org)->create(['start_date' => now()->addDays(5), 'end_date' => now()->addDays(6)]);
        Breakout::factory()->for($event)->create(['title' => 'Youth Track']);
        $program = EventProgram::factory()->for($event)->create(['is_public' => false]);
        $this->registerInEvent($event, $attendee);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/attendee/dashboard')->assertOk()->assertJsonPath('data.upcoming_events.0.breakouts', null);

        $program->update(['is_public' => true]);
        $this->getJson('/api/v1/attendee/dashboard')->assertOk()->assertJsonPath('data.upcoming_events.0.breakouts.0.title', 'Youth Track');
    }

    public function test_migration_down_restores_event_program_unique_and_drops_tables(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_10_22_300000_create_breakouts_tables.php'])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('breakouts'));
        $this->assertFalse(Schema::hasColumn('event_programs', 'breakout_id'));
        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('breakout_picks'));
    }
}
