<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->orgAdmin()->for(Organization::factory())->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $user = User::factory()->orgAdmin()->for(Organization::factory())->create();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
    }

    public function test_authenticated_user_can_fetch_their_profile(): void
    {
        $user = User::factory()->orgAdmin()->for(Organization::factory())->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me');

        $response->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->orgAdmin()->for(Organization::factory())->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/logout');

        $response->assertOk();
        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_user_payload_points_to_unfinished_registration(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->attendee()->create();
        Attendee::factory()->draft()->for($event)->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.pending_registration', $event->invite_token);
    }

    public function test_user_payload_points_to_incomplete_registration(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->attendee()->create();
        Attendee::factory()->draft()->for($event)->create(['user_id' => $user->id, 'first_registered_at' => now()]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.pending_registration', $event->invite_token);
    }

    public function test_user_payload_has_no_pending_registration_once_finished(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->attendee()->create();
        Attendee::factory()->for($event)->create(['user_id' => $user->id, 'registration_completed_at' => now()]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.pending_registration', null);
    }

    public function test_opening_the_wizard_before_the_first_save_marks_the_registration_pending(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->attendee()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/public/event/{$event->invite_token}/registration")->assertOk();

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.pending_registration', $event->invite_token);
    }
}
