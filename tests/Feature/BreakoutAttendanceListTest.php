<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Attendee;
use App\Models\Breakout;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/** The staff list of a Breakout's check-ins, mirroring the Session attendance list. */
class BreakoutAttendanceListTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private Event $event;

    private Breakout $breakout;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->event = Event::factory()->for($this->org)->create();
        $this->breakout = Breakout::factory()->for($this->event)->create();
        $this->admin = User::factory()->orgAdmin()->for($this->org)->create();
    }

    private function checkIn(Breakout $breakout, array $extra = []): AttendanceRecord
    {
        $attendee = Attendee::factory()->for($this->event)->for($this->org)->create();

        return AttendanceRecord::factory()->for($attendee)->create($extra + ['event_session_id' => null, 'breakout_id' => $breakout->id, 'check_in_at' => now()]);
    }

    private function url(?Event $event = null, ?Breakout $breakout = null): string
    {
        return '/api/v1/events/'.($event ?? $this->event)->id.'/breakouts/'.($breakout ?? $this->breakout)->id.'/attendance';
    }

    public function test_lists_only_this_breakouts_check_ins_with_record_ids_walk_ins_included(): void
    {
        $regular = $this->checkIn($this->breakout);
        $walkIn = $this->checkIn($this->breakout, ['is_walk_in' => true]);
        $this->checkIn(Breakout::factory()->for($this->event)->create());

        $response = $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk();

        $this->assertEqualsCanonicalizing([$regular->id, $walkIn->id], $response->json('data.*.id'));
        $this->assertSame([$this->breakout->id], array_unique($response->json('data.*.breakout_id')));
        $this->assertTrue(collect($response->json('data'))->firstWhere('id', $walkIn->id)['is_walk_in']);
        $this->assertNotNull($response->json('data.0.attendee.id'));
    }

    public function test_record_id_narrows_to_that_record(): void
    {
        $this->checkIn($this->breakout);
        $focus = $this->checkIn($this->breakout);

        $this->actingAs($this->admin, 'sanctum')->getJson($this->url().'?record_id='.$focus->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $focus->id);
    }

    public function test_check_ins_of_a_breakout_with_attendance_off_are_not_listed(): void
    {
        $this->checkIn($this->breakout);
        $this->breakout->update(['attendance_enabled' => false]);

        $this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_follows_the_same_role_scoping_as_the_session_list(): void
    {
        $record = $this->checkIn($this->breakout);
        $otherEvent = Event::factory()->for($this->org)->create();
        $restricted = Event::factory()->for($this->org)->create();
        $restrictedBreakout = Breakout::factory()->for($restricted)->create();
        $checker = User::factory()->checker()->for($this->org)->create();
        $checker->accessibleEvents()->attach($this->event);

        $this->getJson($this->url())->assertUnauthorized();
        $this->actingAs(User::factory()->attendee()->for($this->org)->create(), 'sanctum')->getJson($this->url())->assertNotFound();
        $this->actingAs(User::factory()->orgAdmin()->for(Organization::factory()->create())->create(), 'sanctum')->getJson($this->url())->assertNotFound();
        $this->actingAs($checker, 'sanctum')->getJson($this->url())->assertOk()->assertJsonPath('data.0.id', $record->id);
        $this->getJson($this->url($restricted, $restrictedBreakout))->assertForbidden();
        // A breakout never resolves under another event.
        $this->actingAs($this->admin, 'sanctum')->getJson($this->url($otherEvent))->assertNotFound();
    }

    public function test_a_checker_cannot_see_an_archived_event(): void
    {
        $checker = User::factory()->checker()->for($this->org)->create();
        $checker->accessibleEvents()->attach($this->event);
        $this->event->forceFill(['archived_at' => now()])->save();

        $this->actingAs($checker, 'sanctum')->getJson($this->url())->assertNotFound();
    }
}
