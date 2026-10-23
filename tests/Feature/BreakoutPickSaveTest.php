<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\RegistrationCompleteness;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BreakoutPickSaveTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create(['status' => 'published', 'pick_mode' => 'one', 'pick_min' => 1, 'pick_max' => 1]);
        $this->user = User::factory()->attendee()->create(['organization_id' => $this->org->id]);
    }

    private function breakout(string $start = '09:00', string $end = '10:00', ?int $capacity = null): Breakout
    {
        return Breakout::factory()->for($this->event)->create(['date' => '2026-06-25', 'start_time' => $start, 'end_time' => $end, 'capacity' => $capacity]);
    }

    private function attendee(?User $user = null): Attendee
    {
        return Attendee::factory()->draft()->for($this->event)->create(['user_id' => $user?->id ?? User::factory()->attendee()->create(['organization_id' => $this->org->id])->id]);
    }

    private function save(User $user, array $ids)
    {
        return $this->actingAs($user, 'sanctum')->putJson("/api/v1/public/event/{$this->event->invite_token}/registration/breakouts", ['breakout_ids' => $ids]);
    }

    public function test_save_holds_seats_and_clears_release_marker(): void
    {
        $b = $this->breakout();
        $attendee = $this->attendee($this->user);
        $attendee->forceFill(['picks_released_at' => now()])->save();

        $this->save($this->user, [$b->id])->assertOk()->assertJsonPath('data.attendee.breakouts.0.id', $b->id);

        $this->assertSame(1, $b->picks()->count());
        $attendee->refresh();
        $this->assertNull($attendee->picks_released_at);
        $this->assertNotNull($attendee->picks_saved_at);
        $this->assertSame([], $this->save($this->user, [])->json('steps.basic_info'));
    }

    public function test_last_seat_goes_to_first_saver_and_the_second_gets_a_422_naming_the_breakout(): void
    {
        $last = $this->breakout('09:00', '10:00', 1);
        $open = $this->breakout('09:30', '10:30');
        $other = User::factory()->attendee()->create(['organization_id' => $this->org->id]);
        $this->attendee($this->user);
        $this->attendee($other);

        $this->save($this->user, [$last->id])->assertOk();
        $this->save($other, [$last->id])->assertUnprocessable()
            ->assertJsonPath('full_breakouts.0.id', $last->id)
            ->assertJsonFragment(['message' => "\"{$last->title}\" is full."]);

        $this->assertSame(1, $last->picks()->count());
        $this->assertSame(0, $open->picks()->count());
    }

    public function test_all_or_nothing_and_a_held_pick_never_counts_against_the_attendee(): void
    {
        $full = $this->breakout('09:00', '10:00', 1);
        $open = $this->breakout('10:00', '11:00');
        $this->event->forceFill(['pick_mode' => 'multiple', 'pick_min' => 1, 'pick_max' => null])->save();
        $mine = $this->attendee($this->user);
        $this->save($this->user, [$full->id])->assertOk();

        // Already holds the only seat: saving it again with another pick works.
        $this->save($this->user, [$full->id, $open->id])->assertOk();

        $stranger = User::factory()->attendee()->create(['organization_id' => $this->org->id]);
        $strangerAttendee = $this->attendee($stranger);
        $this->save($stranger, [$open->id, $full->id])->assertUnprocessable();
        $this->assertSame(0, $strangerAttendee->breakouts()->count());
        $this->assertSame(2, $mine->breakouts()->count());
    }

    public function test_rejects_overlap_too_many_and_foreign_breakouts(): void
    {
        $a = $this->breakout('09:00', '10:00');
        $b = $this->breakout('09:30', '10:30');
        $foreign = Breakout::factory()->for(Event::factory()->for($this->org)->create())->create();
        $this->attendee($this->user);

        $this->event->forceFill(['pick_mode' => 'multiple', 'pick_min' => 1, 'pick_max' => 2])->save();
        $this->save($this->user, [$a->id, $b->id])->assertUnprocessable();
        $this->save($this->user, [$foreign->id])->assertUnprocessable();
    }

    public function test_no_choice_attendee_is_overbooked_by_exactly_the_shortfall(): void
    {
        $only = $this->breakout('09:00', '10:00', 1);
        $this->attendee()->breakouts()->attach($only->id);

        $mine = $this->attendee($this->user);

        $res = $this->save($this->user, [$only->id])->assertOk()
            ->assertJsonPath('data.attendee.breakouts.0.overbooked', true)
            ->assertJsonPath('steps.breakouts', []);
        $this->assertStringContainsString($only->title, $res->json('note'));
        $this->assertSame(2, $only->picks()->count());
        // A later save keeps the held pick and its flag, and shows no new note.
        $this->save($this->user, [$only->id])->assertOk()->assertJsonPath('note', null)->assertJsonPath('data.attendee.breakouts.0.overbooked', true);
    }

    public function test_attendee_with_a_choice_is_not_overbooked(): void
    {
        $full = $this->breakout('09:00', '10:00', 1);
        $this->breakout('09:30', '10:30');
        $this->attendee()->breakouts()->attach($full->id);
        $this->attendee($this->user);

        $this->save($this->user, [$full->id])->assertUnprocessable();
    }

    public function test_overlap_is_never_relaxed_for_overbooking(): void
    {
        $full = $this->breakout('09:00', '10:00', 1);
        $this->attendee()->breakouts()->attach($full->id);
        $this->event->forceFill(['pick_mode' => 'multiple', 'pick_min' => 2, 'pick_max' => null])->save();
        $this->attendee($this->user);

        // Only 1 reachable non-overlapping slot besides the full one, shortfall is 1: the full one is let in once.
        $this->breakout('11:00', '12:00');
        $this->save($this->user, [$full->id])->assertOk()->assertJsonPath('data.attendee.breakouts.0.overbooked', true);
    }

    public function test_picks_are_final_once_fully_registered_but_an_admin_can_change_them_audited(): void
    {
        $a = $this->breakout('09:00', '10:00', 1);
        $b = $this->breakout('10:00', '11:00');
        $attendee = $this->attendee($this->user);
        $this->save($this->user, [$a->id])->assertOk();
        $attendee->refresh()->update(['registration_confirmed_at' => now()]);
        $this->assertNotNull($attendee->refresh()->registration_completed_at);

        $this->save($this->user, [$b->id])->assertForbidden();

        $admin = User::factory()->orgAdmin()->for($this->org)->create();
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}/breakouts", ['breakout_ids' => []])->assertUnprocessable();
        $other = $this->attendee();
        $other->breakouts()->attach($b->id);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}/breakouts", ['breakout_ids' => [$b->id, $a->id]])->assertUnprocessable(); // max 1

        $this->event->forceFill(['pick_max' => 2, 'pick_mode' => 'multiple'])->save();
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$attendee->id}/breakouts", ['breakout_ids' => [$b->id, $a->id]])->assertOk();
        $this->assertSame(2, $attendee->breakouts()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendee.breakout_picks_changed', 'user_id' => $admin->id]);
    }

    public function test_admin_may_exceed_capacity_on_a_draft_and_other_orgs_are_denied(): void
    {
        $full = $this->breakout('09:00', '10:00', 1);
        $this->attendee()->breakouts()->attach($full->id);
        $draft = $this->attendee();
        $admin = User::factory()->orgAdmin()->for($this->org)->create();

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/attendees/{$draft->id}/breakouts", ['breakout_ids' => [$full->id]])->assertOk();
        $this->assertSame(2, $full->picks()->count());

        $outsider = User::factory()->orgAdmin()->for(Organization::factory()->create())->create();
        $this->actingAs($outsider, 'sanctum')->putJson("/api/v1/attendees/{$draft->id}/breakouts", ['breakout_ids' => []])->assertNotFound();
    }

    public function test_daily_job_releases_only_stale_draft_picks(): void
    {
        $b = $this->breakout();
        $stale = $this->attendee();
        $fresh = $this->attendee();
        $registered = $this->attendee();
        foreach ([$stale, $fresh, $registered] as $a) {
            $a->breakouts()->attach($b->id);
        }
        $stale->forceFill(['picks_saved_at' => now()->subHours(25)])->save();
        $fresh->forceFill(['picks_saved_at' => now()->subHours(23)])->save();
        $registered->forceFill(['picks_saved_at' => now()->subDays(3), 'first_registered_at' => now()->subDays(3)])->save();

        Artisan::call('app:release-stale-draft-picks');

        $this->assertSame(0, $stale->breakouts()->count());
        $this->assertNotNull($stale->refresh()->picks_released_at);
        $this->assertSame(1, $fresh->breakouts()->count());
        $this->assertSame(1, $registered->breakouts()->count());
        $this->assertNull($registered->refresh()->picks_released_at);
    }

    public function test_banner_label_says_pick_your_breakouts(): void
    {
        $this->assertContains('Pick your breakouts', app(RegistrationCompleteness::class)->missingLabels($this->attendee()));
    }

    public function test_user_without_organization_linked_to_an_existing_attendee_can_use_the_wizard(): void
    {
        $b = $this->breakout();
        $user = User::factory()->attendee()->create(['organization_id' => null]);
        $this->attendee($user);
        $base = "/api/v1/public/event/{$this->event->invite_token}/registration";

        $this->save($user, [$b->id])->assertOk()->assertJsonPath('data.attendee.breakouts.0.id', $b->id);
        $this->actingAs($user, 'sanctum')->getJson($base)->assertOk()->assertJsonPath('data.attendee.breakouts.0.id', $b->id);
        $this->actingAs($user, 'sanctum')->getJson("{$base}/forms")->assertOk();
        $this->assertSame($this->org->id, $user->fresh()->organization_id);
    }
}
