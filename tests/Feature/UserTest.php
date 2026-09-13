<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_can_self_update_email(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create([
            'email' => 'old@example.com',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'email' => 'new@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.email', 'new@example.com');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'new@example.com']);
    }

    public function test_user_can_self_update_password(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create([
            'password' => 'oldpassword',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/v1/users/{$user->id}", [
            'password' => 'newpassword123',
        ]);

        $response->assertOk();
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
