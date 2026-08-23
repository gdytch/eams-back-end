<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EventRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registering_an_existing_attendee_generates_a_unique_qr_token(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$event->id}/registrations", [
            'attendee_id' => $attendee->id,
        ]);

        $response->assertCreated();
        $token = $response->json('data.qr_token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'attendee_id' => $attendee->id,
            'qr_token' => $token,
        ]);
    }

    public function test_registering_a_new_attendee_creates_the_attendee_and_registration_together(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson("/api/v1/events/{$event->id}/registrations", [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]);

        $response->assertCreated();
        $this->assertSame(1, Attendee::where('organization_id', $org->id)->count());
        $this->assertDatabaseHas('event_registrations', ['event_id' => $event->id]);
    }

    public function test_two_registrations_never_share_a_qr_token(): void
    {
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
}
