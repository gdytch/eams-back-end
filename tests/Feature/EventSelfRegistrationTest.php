<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EventSelfRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unauthenticated_user_cannot_self_register_to_event(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();

        $response = $this->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $response->assertUnauthorized();
    }

    public function test_authenticated_attendee_user_can_self_register_to_event(): void
    {
        Bus::fake();

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();
        $user = User::factory()->attendee()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $response->assertCreated();
        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
        ]);
    }

    public function test_self_registration_creates_attendee_if_user_has_none(): void
    {
        Bus::fake();

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();
        $user = User::factory()
            ->attendee()
            ->state(['first_name' => 'Jane', 'last_name' => 'Doe'])
            ->create(['organization_id' => null]);

        $this->assertNull($user->attendee);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $response->assertCreated();
        $this->assertNotNull($user->fresh()->attendee);
        $this->assertDatabaseHas('attendees', [
            'user_id' => $user->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'organization_id' => $event->organization_id,
        ]);
    }

    public function test_self_registration_uses_existing_attendee(): void
    {
        Bus::fake();

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();
        $user = User::factory()->attendee()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->for($user)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $response->assertCreated();
        $this->assertDatabaseHas('event_registrations', [
            'event_id' => $event->id,
            'attendee_id' => $attendee->id,
        ]);
    }

    public function test_self_registration_is_idempotent(): void
    {
        Bus::fake();

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();
        $user = User::factory()->attendee()->for($org)->create();

        $response1 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $response2 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $response1->assertCreated();
        $response2->assertOk(); // Second call returns 200 (already registered)
        $this->assertSame($response1->json('data.id'), $response2->json('data.id'));

        // Only one registration should exist
        $this->assertCount(1, $event->registrations);
    }

    public function test_self_registration_returns_404_for_draft_event(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Draft])->create();
        $user = User::factory()->attendee()->for($org)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $response->assertNotFound();
    }

    public function test_checker_can_self_register_to_event(): void
    {
        Bus::fake();

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $response->assertCreated();
        // Checker should have an attendee created
        $this->assertNotNull($checker->fresh()->attendee);
    }

    public function test_org_admin_can_self_register_to_event(): void
    {
        Bus::fake();

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $response->assertCreated();
        $this->assertNotNull($admin->fresh()->attendee);
    }

    public function test_self_registration_sets_org_id_if_user_org_is_null(): void
    {
        Bus::fake();

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();
        $user = User::factory()->attendee()->state(['organization_id' => null])->create();

        $this->assertNull($user->organization_id);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $this->assertSame($org->id, $user->fresh()->organization_id);
    }

    public function test_self_registration_does_not_override_existing_org_id(): void
    {
        Bus::fake();

        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $event = Event::factory()->for($orgB)->state(['status' => EventStatus::Published])->create();
        $user = User::factory()->attendee()->for($orgA)->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        // User's org should stay as orgA
        $this->assertSame($orgA->id, $user->fresh()->organization_id);
    }

    public function test_self_registration_returns_qr_token(): void
    {
        Bus::fake();

        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->state(['status' => EventStatus::Published])->create();
        $user = User::factory()->attendee()->for($org)->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/public/events/{$event->invite_token}/register");

        $response->assertCreated();
        $this->assertNotEmpty($response->json('data.qr_token'));
    }
}
