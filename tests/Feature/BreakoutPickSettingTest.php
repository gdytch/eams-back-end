<?php

namespace Tests\Feature;

use App\Jobs\RecomputeEventCompletenessJob;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\RegistrationCompleteness;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BreakoutPickSettingTest extends TestCase
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
        $this->url = "/api/v1/events/{$this->event->id}/breakout-picks";
        $this->actingAs(User::factory()->orgAdmin()->for($this->org)->create(), 'sanctum');
    }

    private function breakout(string $start, string $end, string $date = '2026-06-25'): Breakout
    {
        return Breakout::factory()->for($this->event)->create(['date' => $date, 'start_time' => $start, 'end_time' => $end]);
    }

    private function pick(Attendee $attendee, Breakout ...$breakouts): void
    {
        $attendee->breakouts()->attach(collect($breakouts)->pluck('id'));
    }

    private function enable(array $setting = ['mode' => 'one']): void
    {
        $this->event->forceFill(['pick_mode' => $setting['mode'], 'pick_min' => $setting['min'] ?? 1, 'pick_max' => $setting['max'] ?? ($setting['mode'] === 'one' ? 1 : null)])->save();
    }

    public function test_enable_one_and_multiple_and_disable(): void
    {
        $this->breakout('09:00', '10:00');
        $this->breakout('10:00', '11:00');

        $this->putJson($this->url, ['mode' => 'one'])->assertOk()
            ->assertJsonPath('data.breakout_picks', ['mode' => 'one', 'min' => 1, 'max' => 1]);
        $this->putJson($this->url, ['mode' => 'multiple'])->assertOk()
            ->assertJsonPath('data.breakout_picks', ['mode' => 'multiple', 'min' => 1, 'max' => null]);
        $this->putJson($this->url, ['mode' => 'multiple', 'min' => 0, 'max' => 2])->assertOk()
            ->assertJsonPath('data.breakout_picks.min', 0);
        $this->putJson($this->url, ['mode' => null])->assertOk()->assertJsonPath('data.breakout_picks', null);
    }

    public function test_validation_of_min_and_max(): void
    {
        $this->breakout('09:00', '10:00');

        $this->putJson($this->url, ['mode' => 'multiple', 'min' => 3, 'max' => 2])->assertUnprocessable();
        $this->putJson($this->url, ['mode' => 'multiple', 'max' => 0])->assertUnprocessable()->assertJsonValidationErrors('max');
        $this->putJson($this->url, ['mode' => 'multiple', 'max' => 3])->assertOk();
        $this->putJson($this->url, ['mode' => 'weekly'])->assertUnprocessable();
    }

    public function test_cannot_enable_without_breakouts(): void
    {
        $this->putJson($this->url, ['mode' => 'one'])->assertUnprocessable()->assertJsonValidationErrors('mode');
    }

    public function test_min_above_largest_non_overlapping_set_returns_422(): void
    {
        $this->breakout('09:00', '10:00');
        $this->breakout('09:30', '10:30');

        $this->putJson($this->url, ['mode' => 'multiple', 'min' => 2])->assertUnprocessable();
        $this->putJson($this->url, ['mode' => 'multiple', 'min' => 1])->assertOk();
    }

    public function test_disabling_with_picks_returns_422(): void
    {
        $breakout = $this->breakout('09:00', '10:00');
        $this->enable();
        $this->pick(Attendee::factory()->for($this->event)->create(), $breakout);

        $this->putJson($this->url, ['mode' => null])->assertUnprocessable();
        $this->assertSame('one', $this->event->fresh()->pick_mode);
    }

    public function test_tightening_blocked_when_picks_break_it_but_loosening_is_allowed(): void
    {
        $a = $this->breakout('09:00', '10:00');
        $b = $this->breakout('10:00', '11:00');
        $this->enable(['mode' => 'multiple', 'min' => 1, 'max' => 2]);
        $this->pick(Attendee::factory()->for($this->event)->create(), $a, $b);

        $this->putJson($this->url, ['mode' => 'one'])->assertUnprocessable();
        $this->putJson($this->url, ['mode' => 'multiple', 'min' => 1, 'max' => 1])->assertUnprocessable();
        $this->putJson($this->url, ['mode' => 'multiple', 'min' => 0, 'max' => 5])->assertOk();
    }

    public function test_raising_min_above_a_partial_pick_is_allowed(): void
    {
        $a = $this->breakout('09:00', '10:00');
        $this->breakout('10:00', '11:00');
        $this->enable(['mode' => 'multiple', 'min' => 1, 'max' => 2]);
        $this->pick(Attendee::factory()->for($this->event)->draft()->create(), $a);

        $this->putJson($this->url, ['mode' => 'multiple', 'min' => 2, 'max' => 2])->assertOk();
    }

    public function test_enabling_asks_for_confirmation_then_matches_the_dry_run_count(): void
    {
        Queue::fake();
        $this->breakout('09:00', '10:00');
        $attendees = Attendee::factory()->for($this->event)->count(2)->create();
        Attendee::factory()->for($this->event)->draft()->create();
        $this->assertNotNull($attendees[0]->registration_completed_at);

        $this->putJson($this->url, ['mode' => 'one'])->assertStatus(409)
            ->assertJson(['requires_confirmation' => true, 'would_become_incomplete' => 2]);
        $this->assertNull($this->event->fresh()->pick_mode);
        Queue::assertNothingPushed();

        $this->putJson($this->url, ['mode' => 'one', 'confirm' => true])->assertOk();
        Queue::assertPushed(RecomputeEventCompletenessJob::class);

        (new RecomputeEventCompletenessJob($this->event->fresh()))->handle(app(RegistrationCompleteness::class));
        $this->assertSame(0, Attendee::where('event_id', $this->event->id)->whereNotNull('registration_completed_at')->count());
    }

    public function test_attendee_with_enough_picks_stays_complete(): void
    {
        $breakout = $this->breakout('09:00', '10:00');
        $attendee = Attendee::factory()->for($this->event)->create();
        $this->pick($attendee, $breakout);
        Queue::fake();

        $this->putJson($this->url, ['mode' => 'one'])->assertOk();
    }

    public function test_completeness_requires_picks_when_enabled(): void
    {
        $a = $this->breakout('09:00', '10:00');
        $b = $this->breakout('09:30', '10:30');
        $this->enable(['mode' => 'multiple', 'min' => 1, 'max' => 2]);
        $attendee = Attendee::factory()->for($this->event)->create();
        $this->assertNull($attendee->registration_completed_at);

        $this->pick($attendee, $a, $b); // overlapping picks do not count
        $attendee = $attendee->fresh();
        $attendee->save();
        $this->assertNull($attendee->registration_completed_at);

        $attendee->breakouts()->detach($b->id);
        $attendee = $attendee->fresh();
        $attendee->registration_confirmed_at = now();
        $attendee->save();
        $this->assertNotNull($attendee->registration_completed_at);
    }

    public function test_time_edit_that_overlaps_an_attendees_picks_returns_422(): void
    {
        $a = $this->breakout('09:00', '10:00');
        $b = $this->breakout('10:00', '11:00');
        $this->enable(['mode' => 'multiple', 'min' => 0]);
        $this->pick(Attendee::factory()->for($this->event)->create(), $a, $b);
        $payload = fn (string $start, string $end) => [
            'title' => 'T', 'date' => '2026-06-25', 'start_time' => $start, 'end_time' => $end,
            'room' => 'R', 'topics' => ['x'], 'speaker_ids' => [],
        ];

        $this->putJson("/api/v1/events/{$this->event->id}/breakouts/{$b->id}", $payload('09:30', '10:30'))->assertUnprocessable();
        $this->assertStringStartsWith('10:00', $b->fresh()->start_time);
        $this->putJson("/api/v1/events/{$this->event->id}/breakouts/{$b->id}", $payload('10:00', '10:45'))->assertOk();
    }

    public function test_time_edit_or_delete_that_pushes_the_set_below_min_returns_422(): void
    {
        $a = $this->breakout('09:00', '10:00');
        $b = $this->breakout('10:00', '11:00');
        $this->enable(['mode' => 'multiple', 'min' => 2]);
        $base = "/api/v1/events/{$this->event->id}/breakouts";

        $this->putJson("$base/{$b->id}", [
            'title' => 'T', 'date' => '2026-06-25', 'start_time' => '09:30', 'end_time' => '10:30',
            'room' => 'R', 'topics' => ['x'], 'speaker_ids' => [],
        ])->assertUnprocessable();
        $this->deleteJson("$base/{$b->id}")->assertUnprocessable();
        $this->assertDatabaseHas('breakouts', ['id' => $b->id]);

        $this->enable(['mode' => 'multiple', 'min' => 1]);
        $this->deleteJson("$base/{$b->id}")->assertNoContent();
    }

    public function test_only_event_admins_can_change_the_setting(): void
    {
        $this->breakout('09:00', '10:00');
        $this->actingAs(User::factory()->orgAdmin()->for(Organization::factory()->create())->create(), 'sanctum');

        $this->putJson($this->url, ['mode' => 'one'])->assertNotFound();
    }
}
