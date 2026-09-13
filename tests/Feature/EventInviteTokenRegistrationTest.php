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
        $response->assertJsonPath('errors.email.0', 'Invalid invite token.');
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
        $org = Organization::factory()->create();
        $attendee = Attendee::factory()->for($org)->create([
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
    }

    public function test_can_merge_admin_created_attendee_via_event_invite_token(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();
        $attendee = Attendee::factory()->for($org)->create([
            'email_address' => 'admin@example.com',
            'invite_token' => Attendee::generateUniqueInviteToken(),
            'invited_at' => now(),
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
        $user = User::where('email', 'admin@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        $attendee->refresh();
        $this->assertEquals($user->id, $attendee->user_id);
        $this->assertNull($attendee->invite_token);
    }

    public function test_cannot_merge_attendee_from_different_org_via_event_invite_token(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $event = Event::factory()->for($org1)->create();
        Attendee::factory()->for($org2)->create([
            'email_address' => 'cross@example.com',
            'user_id' => null,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Cross',
            'last_name' => 'Org',
            'email' => 'cross@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $event->invite_token,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.email.0', 'This email address is already in use.');
    }

    public function test_cannot_merge_already_claimed_attendee_via_event_invite_token(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()->for($org)->create();
        $existingUser = User::factory()->for($org)->create();
        Attendee::factory()->for($org)->for($existingUser)->create([
            'email_address' => 'claimed@example.com',
            'user_id' => $existingUser->id,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'claimed@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $event->invite_token,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.email.0', 'This email address is already in use.');
    }
}
