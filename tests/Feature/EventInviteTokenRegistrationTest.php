<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class EventInviteTokenRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_register_with_event_invite_token(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Event',
            'last_name' => 'User',
            'email' => 'event@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $event->invite_token,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'event@example.com']);
    }

    public function test_cannot_register_with_invalid_invite_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => 'invalid_token',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['invite_token']);
    }

    public function test_event_invite_token_does_not_verify_email(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Event',
            'last_name' => 'User',
            'email' => 'event@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $event->invite_token,
        ]);

        $response->assertCreated();
        $user = User::where('email', 'event@example.com')->first();
        $this->assertNull($user->email_verified_at);
    }

    public function test_attendee_invite_token_still_works(): void
    {
        $event = Event::factory()->create();
        $attendee = Attendee::factory()->for($event)->create([
            'email_address' => 'attendee@example.com',
            'invite_token' => Attendee::generateUniqueInviteToken(),
            'invited_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Attendee',
            'last_name' => 'User',
            'email' => 'attendee@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $attendee->invite_token,
        ]);

        $response->assertCreated();
        $user = User::where('email', 'attendee@example.com')->first();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame($user->id, $attendee->fresh()->user_id);
    }

    public function test_event_invite_token_does_not_link_an_attendee_with_the_same_email(): void
    {
        $event = Event::factory()->create();
        $attendee = Attendee::factory()->for($event)->create([
            'email_address' => 'admin@example.com',
            'user_id' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Admin',
            'last_name' => 'Attendee',
            'email' => 'admin@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $event->invite_token,
        ]);

        $response->assertCreated();
        $this->assertNull(User::where('email', 'admin@example.com')->first()->email_verified_at);
        $this->assertNull($attendee->fresh()->user_id);
    }

    public function test_expired_attendee_invite_token_is_rejected(): void
    {
        $attendee = Attendee::factory()->for(Event::factory()->create())->create([
            'email_address' => 'late@example.com',
            'invite_token' => Attendee::generateUniqueInviteToken(),
            'invited_at' => now()->subDays(31),
        ]);

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Late',
            'last_name' => 'User',
            'email' => 'late@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $attendee->invite_token,
        ])->assertUnprocessable()->assertJsonValidationErrors(['invite_token']);

        $this->assertNull($attendee->fresh()->user_id);
    }
}
