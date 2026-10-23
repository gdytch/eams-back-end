<?php

namespace Tests\Feature;

use App\Jobs\GenerateAttendeeIdCardJob;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registering_a_known_person_creates_a_prefilled_attendee_for_the_event(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $earlier = Attendee::factory()->for(Event::factory()->for($org)->create())->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$event->id}/registrations", [
            'attendee_id' => $earlier->id,
        ]);

        $response->assertCreated();
        $token = $response->json('data.qr_token');
        $this->assertNotEmpty($token);
        $this->assertNotSame($earlier->qr_token, $token);
        $this->assertNotSame($earlier->id, $response->json('data.attendee_id'));
        $this->assertDatabaseHas('attendees', [
            'id' => $response->json('data.attendee_id'),
            'event_id' => $event->id,
            'organization_id' => $org->id,
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'registered_by' => $checker->id,
            'qr_token' => $token,
        ]);
        Queue::assertPushed(GenerateAttendeeIdCardJob::class, fn ($job) => $job->attendee->id === $response->json('data.attendee_id'));
    }

    public function test_a_person_cannot_be_registered_twice_in_the_same_event(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $user = User::factory()->attendee()->for($org)->create();
        $registered = Attendee::factory()->for($event)->for($user)->create();
        $other = Attendee::factory()->for(Event::factory()->for($org)->create())->for($user)->create();

        $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$event->id}/registrations", ['attendee_id' => $registered->id])
            ->assertUnprocessable()->assertJsonValidationErrors('attendee_id');
        $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$event->id}/registrations", ['attendee_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('attendee_id');
        $this->assertSame(1, Attendee::where('event_id', $event->id)->count());
    }

    public function test_registering_a_new_attendee_creates_the_attendee_and_registration_together(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$event->id}/registrations", [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);

        $response->assertCreated();
        $this->assertSame(1, Attendee::where('organization_id', $org->id)->count());
        $this->assertDatabaseHas('attendees', ['event_id' => $event->id, 'first_name' => 'Maria', 'organization_id' => $org->id]);
    }

    public function test_two_registrations_never_share_a_qr_token(): void
    {
        Queue::fake();
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $attendeeA = Attendee::factory()->for($org)->create();
        $attendeeB = Attendee::factory()->for($org)->create();

        $tokenA = $this->actingAs($checker, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/registrations", ['attendee_id' => $attendeeA->id])
            ->json('data.qr_token');

        $tokenB = $this->actingAs($checker, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/registrations", ['attendee_id' => $attendeeB->id])
            ->json('data.qr_token');

        $this->assertNotNull($tokenA);
        $this->assertNotSame($tokenA, $tokenB);
    }

    public function test_checker_cannot_register_attendee_for_event_outside_their_organization(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $checker = User::factory()->checker()->for($orgA)->create();
        $eventB = Event::factory()->for($orgB)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$eventB->id}/registrations", [
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        $response->assertNotFound();
    }

    public function test_registrations_search_matches_a_full_name_in_either_order(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $target = Attendee::factory()->for($event)->create(['first_name' => 'Scan', 'middle_name' => 'Q', 'last_name' => 'Target']);
        Attendee::factory()->for($event)->create(['first_name' => 'Other', 'last_name' => 'Person']);

        foreach (['Scan Target', 'Target Scan', 'scan q target', 'Scan'] as $search) {
            $ids = collect($this->actingAs($checker, 'sanctum')->getJson("/api/v1/events/{$event->id}/registrations?search=".rawurlencode($search))->assertOk()->json('data'))->pluck('attendee_id');
            $this->assertSame([$target->id], $ids->all(), $search);
        }
    }
}
