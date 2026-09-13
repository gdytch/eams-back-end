<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DuplicateEmailPreventionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cannot_register_user_with_email_that_exists_in_attendees_table(): void
    {
        $org = Organization::factory()->create();
        Attendee::factory()->for($org)->create([
            'email_address' => 'test@example.com',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.email.0', 'This email address is already in use.');
    }

    public function test_cannot_register_user_with_email_that_exists_in_users_table(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'existing@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.email.0', 'This email address is already in use.');
    }

    public function test_can_register_with_invite_token_email(): void
    {
        $org = Organization::factory()->create();
        $attendee = Attendee::factory()->for($org)->create([
            'email_address' => 'invite@example.com',
            'invite_token' => Attendee::generateUniqueInviteToken(),
            'invited_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'invite@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'invite_token' => $attendee->invite_token,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'invite@example.com']);
        $this->assertDatabaseHas('attendees', [
            'email_address' => 'invite@example.com',
            'user_id' => User::where('email', 'invite@example.com')->first()->id,
        ]);
    }

    public function test_cannot_create_attendee_with_email_that_exists_in_users_table(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        User::factory()->for($org)->create(['email' => 'test@example.com']);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email_address' => 'test@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.email_address.0', 'This email address is already in use by another attendee or account.');
    }

    public function test_cannot_create_attendee_with_duplicate_email(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        Attendee::factory()->for($org)->create([
            'email_address' => 'test@example.com',
        ]);

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email_address' => 'test@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.email_address.0', 'This email address is already in use by another attendee or account.');
    }

    public function test_cannot_register_event_attendee_with_duplicate_email(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();
        $event = Event::factory()->for($org)->create();
        User::factory()->for($org)->create(['email' => 'test@example.com']);

        $response = $this->actingAs($checker, 'sanctum')
            ->postJson("/api/v1/events/{$event->id}/registrations", [
                'first_name' => 'Test',
                'last_name' => 'User',
                'email_address' => 'test@example.com',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.email_address.0', 'This email address is already in use by another attendee or account.');
    }

    public function test_can_create_attendee_without_email(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendees', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email_address' => null,
        ]);
    }

    public function test_can_create_multiple_attendees_without_email(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response1 = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Test1',
            'last_name' => 'User',
        ]);

        $response2 = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/attendees', [
            'first_name' => 'Test2',
            'last_name' => 'User',
        ]);

        $response1->assertCreated();
        $response2->assertCreated();
        $this->assertCount(2, Attendee::where('last_name', 'User')->get());
    }
}
