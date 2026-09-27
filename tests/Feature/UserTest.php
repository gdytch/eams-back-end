<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_old_bearer_tokens_expire(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('old');
        $token->accessToken->forceFill(['created_at' => now()->subDays(8)])->save();
        $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_user_cannot_change_email_through_generic_update(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create([
            'email' => 'old@example.com',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'email' => 'new@example.com',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'old@example.com']);
    }

    public function test_user_can_self_update_password(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create([
            'password' => 'oldpassword',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'password' => 'newpassword123',
            'current_password' => 'oldpassword',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    public function test_user_must_supply_current_password_to_change_password(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create(['password' => 'oldpassword']);

        $this->actingAs($user, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'password' => 'newpassword123',
            'current_password' => 'wrongpassword',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_password_change_revokes_other_tokens_but_keeps_current_token(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create(['password' => 'oldpassword']);
        $current = $user->createToken('current')->plainTextToken;
        $other = $user->createToken('other')->plainTextToken;

        $this->withToken($current)->putJson("/api/v1/users/{$user->id}", [
            'password' => 'newpassword123',
            'current_password' => 'oldpassword',
        ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'current']);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'other']);
        $changes = AuditLog::where('action', 'user.updated')->latest('id')->firstOrFail()->changes;
        $this->assertArrayNotHasKey('password', $changes);
        $this->assertArrayNotHasKey('current_password', $changes);
    }

    public function test_org_admin_email_change_clears_verification_and_tokens(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $target = User::factory()->checker()->for($org)->create(['email' => 'old@example.com']);

        $target->createToken('target-token');
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$target->id}", [
            'email' => 'new@example.com',
        ])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'email' => 'new@example.com', 'email_verified_at' => null]);
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_org_admin_cannot_promote_user_to_super_admin(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $checker = User::factory()->checker()->for($org)->create();

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$checker->id}", [
            'role' => 'super_admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertTrue($checker->fresh()->isChecker());
    }

    public function test_org_admin_cannot_create_user_in_another_organization(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Foreign User',
            'email' => 'foreign@example.com',
            'password' => 'password123',
            'role' => 'checker',
            'organization_id' => $otherOrg->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->assertDatabaseMissing('users', ['email' => 'foreign@example.com']);
    }

    public function test_checker_cannot_edit_event_access_even_for_self(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $this->actingAs($checker, 'sanctum')->putJson("/api/v1/users/{$checker->id}/event-access", [
            'event_ids' => [],
        ])->assertForbidden();
    }

    public function test_user_can_self_update_name_fields(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create([
            'name' => 'John Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.first_name', 'Jane');
        $response->assertJsonPath('data.last_name', 'Smith');
    }

    public function test_attendee_cannot_update_name_fields(): void
    {
        $org = Organization::factory()->create();
        $attendee = User::factory()->for($org)->create([
            'role' => 'attendee',
            'name' => 'John Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $response = $this->actingAs($attendee, 'sanctum')->putJson("/api/v1/users/{$attendee->id}", [
            'first_name' => 'Jane',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('first_name');
    }

    public function test_user_cannot_change_own_role(): void
    {
        $org = Organization::factory()->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker, 'sanctum')->putJson("/api/v1/users/{$checker->id}", [
            'role' => 'org_admin',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('role');
        $checker->refresh();
        $this->assertTrue($checker->isChecker());
    }

    public function test_org_admin_can_change_checker_name(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $checker = User::factory()->checker()->for($org)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$checker->id}", [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.first_name', 'Jane');
        $response->assertJsonPath('data.last_name', 'Smith');
    }

    public function test_org_admin_can_change_checker_role(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $checker = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$checker->id}", [
            'role' => 'org_admin',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.role', 'org_admin');
    }

    public function test_checker_cannot_update_another_user(): void
    {
        $org = Organization::factory()->create();
        $checker1 = User::factory()->checker()->for($org)->create();
        $checker2 = User::factory()->checker()->for($org)->create();

        $response = $this->actingAs($checker1, 'sanctum')->putJson("/api/v1/users/{$checker2->id}", [
            'email' => 'newemail@example.com',
        ]);

        $response->assertForbidden();
    }

    public function test_user_response_includes_name_fields(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create([
            'name' => 'John Q Doe',
            'first_name' => 'John',
            'middle_name' => 'Q',
            'last_name' => 'Doe',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/users/{$user->id}");

        $response->assertOk();
        $response->assertJsonPath('data.first_name', 'John');
        $response->assertJsonPath('data.middle_name', 'Q');
        $response->assertJsonPath('data.last_name', 'Doe');
    }

    public function test_user_name_update_syncs_to_attendee(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create([
            'role' => 'attendee',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $attendee = Attendee::factory()->for($org)->for($user)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        // Admin updates user's name
        $admin = User::factory()->orgAdmin()->for($org)->create();
        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        $response->assertOk();

        // Verify user name changed
        $user->refresh();
        $this->assertEquals('Jane', $user->first_name);
        $this->assertEquals('Smith', $user->last_name);

        // Verify attendee name also changed
        $attendee->refresh();
        $this->assertEquals('Jane', $attendee->first_name);
        $this->assertEquals('Smith', $attendee->last_name);
    }

    public function test_user_middle_name_update_syncs_to_attendee(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create([
            'role' => 'attendee',
            'middle_name' => 'Q',
        ]);
        $attendee = Attendee::factory()->for($org)->for($user)->create([
            'middle_name' => 'Q',
        ]);

        $admin = User::factory()->orgAdmin()->for($org)->create();
        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'middle_name' => 'Alexander',
        ]);

        $response->assertOk();

        $attendee->refresh();
        $this->assertEquals('Alexander', $attendee->middle_name);
    }
}
