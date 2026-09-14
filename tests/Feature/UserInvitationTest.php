<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\UserAccountInviteMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_org_admin_can_invite_user_by_email(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'newuser@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'role' => UserRole::Checker->value,
            'organization_id' => $org->id,
            'invited_at' => now()->toDateTimeString(),
        ]);
        Mail::assertQueued(UserAccountInviteMail::class);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user->invite_token);
        $this->assertTrue($user->isPendingInvite());
    }

    public function test_super_admin_can_specify_organization_when_inviting(): void
    {
        Mail::fake();
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'newuser@example.com',
            'role' => UserRole::OrgAdmin->value,
            'organization_id' => $org2->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'role' => UserRole::OrgAdmin->value,
            'organization_id' => $org2->id,
        ]);
    }

    public function test_checker_cannot_invite_user(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'newuser@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_invite_with_duplicate_email(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $existing = User::factory()->for($org)->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'existing@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $response->assertStatus(422);
    }

    public function test_get_invite_returns_user_details(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'invite@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $user = User::where('email', 'invite@example.com')->first();
        $token = $user->invite_token;

        $response = $this->getJson("/api/v1/auth/invite/{$token}");

        $response->assertStatus(200);
        $response->assertJson([
            'email' => 'invite@example.com',
            'role' => UserRole::Checker->value,
            'organization' => $org->name,
        ]);
    }

    public function test_get_invite_returns_404_for_invalid_token(): void
    {
        $response = $this->getJson('/api/v1/auth/invite/invalid-token');

        $response->assertStatus(404);
    }

    public function test_accept_invite_with_valid_token_and_matching_email(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'invite@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $user = User::where('email', 'invite@example.com')->first();
        $token = $user->invite_token;

        $response = $this->postJson('/api/v1/auth/accept-invite', [
            'token' => $token,
            'email' => 'invite@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['token', 'user']);

        $user->refresh();
        $this->assertNull($user->invite_token);
        $this->assertNotNull($user->email_verified_at);
        $this->assertEquals('John', $user->first_name);
        $this->assertEquals('Doe', $user->last_name);
    }

    public function test_accept_invite_requires_matching_email(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'invite@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $user = User::where('email', 'invite@example.com')->first();
        $token = $user->invite_token;

        $response = $this->postJson('/api/v1/auth/accept-invite', [
            'token' => $token,
            'email' => 'wrong@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_resend_invite_for_pending_user(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'invite@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $user = User::where('email', 'invite@example.com')->first();
        $originalToken = $user->invite_token;

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/users/{$user->id}/resend-invite");

        $response->assertStatus(200);
        Mail::assertQueued(UserAccountInviteMail::class);

        $user->refresh();
        $this->assertNotEquals($originalToken, $user->invite_token);
    }

    public function test_cannot_resend_invite_for_accepted_user(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'invite@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $user = User::where('email', 'invite@example.com')->first();
        $token = $user->invite_token;

        // Accept the invite
        $this->postJson('/api/v1/auth/accept-invite', [
            'token' => $token,
            'email' => 'invite@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/users/{$user->id}/resend-invite");

        $response->assertStatus(422);
    }

    public function test_cancel_invite_for_pending_user(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'invite@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $user = User::where('email', 'invite@example.com')->first();
        $userId = $user->id;

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$userId}/cancel-invite");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_cannot_cancel_invite_for_accepted_user(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'invite@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $user = User::where('email', 'invite@example.com')->first();
        $token = $user->invite_token;

        // Accept the invite
        $this->postJson('/api/v1/auth/accept-invite', [
            'token' => $token,
            'email' => 'invite@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/users/{$user->id}/cancel-invite");

        $response->assertStatus(422);
    }

    public function test_user_resource_shows_invite_status(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        // Create a pending invite
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users/invite', [
            'email' => 'pending@example.com',
            'role' => UserRole::Checker->value,
        ]);

        $pendingUser = User::where('email', 'pending@example.com')->first();

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/users/{$pendingUser->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.email', 'pending@example.com');
        $response->assertJsonPath('data.invite_status', 'pending');

        // Accept the invite
        $token = $pendingUser->invite_token;
        $this->postJson('/api/v1/auth/accept-invite', [
            'token' => $token,
            'email' => 'pending@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $acceptedUser = User::where('email', 'pending@example.com')->first();
        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/users/{$acceptedUser->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.email', 'pending@example.com');
        $response->assertJsonPath('data.invite_status', 'accepted');
    }
}
