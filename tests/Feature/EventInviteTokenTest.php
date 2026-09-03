<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EventInviteTokenTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creating_an_event_auto_generates_a_unique_invite_token(): void
    {
        $org = Organization::factory()->create();

        $event = Event::factory()->for($org)->create();

        $this->assertNotEmpty($event->invite_token);
        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'invite_token' => $event->invite_token,
        ]);
    }

    public function test_invite_token_is_unique(): void
    {
        $org = Organization::factory()->create();

        $eventA = Event::factory()->for($org)->create();
        $eventB = Event::factory()->for($org)->create();

        $this->assertNotSame($eventA->invite_token, $eventB->invite_token);
    }

    public function test_invite_token_cannot_be_manually_overridden(): void
    {
        $org = Organization::factory()->create();

        $customToken = 'custom-token-12345';
        $event = Event::factory()->for($org)->create(['invite_token' => $customToken]);

        // The token should be regenerated, not use the manual value
        $this->assertNotSame($customToken, $event->invite_token);
        $this->assertNotEmpty($event->invite_token);
    }
}
