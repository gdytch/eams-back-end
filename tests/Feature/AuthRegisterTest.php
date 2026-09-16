<?php

namespace Tests\Feature;

use App\Enums\OrganizationLevel;
use App\Enums\UserRole;
use App\Models\Attendee;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthRegisterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function validRegistrationPayload(array $overrides = []): array
    {
        $organization = Organization::factory()->create();
        $union = Union::factory()->for($organization)->create();

        return array_merge([
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'organization_id' => $organization->id,
            'union_id' => $union->id,
            'organization_level' => OrganizationLevel::Union->value,
        ], $overrides);
    }

    public function test_user_can_register_with_valid_data(): void
    {
        $payload = $this->validRegistrationPayload([
            'middle_name' => 'Paul',
        ]);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);
        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    }

    public function test_registered_user_always_has_attendee_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'jane@example.com',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]));

        $this->assertSame(UserRole::Attendee->value, $response->json('user.role'));
        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'role' => UserRole::Attendee->value,
        ]);
    }

    public function test_registration_ignores_role_field_in_request(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'hacker@example.com',
            'first_name' => 'Admin',
            'last_name' => 'Hacker',
            'role' => 'super_admin',
        ]));

        $response->assertCreated();
        $this->assertSame(UserRole::Attendee->value, $response->json('user.role'));
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'existing@example.com',
            'first_name' => 'Duplicate',
            'last_name' => 'User',
        ]));

        $response->assertUnprocessable();
    }

    public function test_registration_fails_with_mismatched_passwords(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'password_confirmation' => 'different-password',
        ]));

        $response->assertUnprocessable();
    }

    public function test_registration_fails_with_missing_required_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'incomplete@example.com',
        ]);

        $response->assertUnprocessable();
    }

    public function test_registered_user_is_auto_logged_in(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'maria@example.com',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
        ]));

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $meResponse->assertOk();
        $this->assertSame('maria@example.com', $meResponse->json('user.email'));
    }

    public function test_registration_stores_name_parts(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'john.m.johnson@example.com',
            'first_name' => 'John',
            'middle_name' => 'Michael',
            'last_name' => 'Johnson',
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'john.m.johnson@example.com',
            'first_name' => 'John',
            'middle_name' => 'Michael',
            'last_name' => 'Johnson',
        ]);
    }

    public function test_registration_fails_without_organization_id(): void
    {
        $payload = $this->validRegistrationPayload();
        unset($payload['organization_id']);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors(['organization_id']);
    }

    public function test_registration_fails_without_union_id(): void
    {
        $payload = $this->validRegistrationPayload();
        unset($payload['union_id']);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors(['union_id']);
    }

    public function test_registration_fails_without_organization_level(): void
    {
        $payload = $this->validRegistrationPayload();
        unset($payload['organization_level']);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors(['organization_level']);
    }

    public function test_registration_succeeds_with_mission_id_optional(): void
    {
        $payload = $this->validRegistrationPayload();
        // mission_id intentionally omitted

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => $payload['email']]);
    }

    public function test_registration_fails_when_union_id_not_in_organization(): void
    {
        $organization = Organization::factory()->create();
        $union = Union::factory()->create(); // Different organization

        $payload = $this->validRegistrationPayload([
            'organization_id' => $organization->id,
            'union_id' => $union->id,
        ]);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors(['union_id']);
    }

    public function test_registration_fails_when_mission_id_not_in_organization(): void
    {
        $organization = Organization::factory()->create();
        $union = Union::factory()->for($organization)->create();
        $differentOrgMission = Mission::factory()->create(); // Different organization

        $payload = $this->validRegistrationPayload([
            'organization_id' => $organization->id,
            'union_id' => $union->id,
            'mission_id' => $differentOrgMission->id,
        ]);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors(['mission_id']);
    }

    public function test_self_registration_creates_attendee_record(): void
    {
        $payload = $this->validRegistrationPayload([
            'middle_name' => 'Alexander',
        ]);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated();
        $user = User::where('email', $payload['email'])->first();

        $this->assertDatabaseHas('attendees', [
            'user_id' => $user->id,
            'organization_id' => $payload['organization_id'],
            'union_id' => $payload['union_id'],
            'mission_id' => null,
            'organization_level' => $payload['organization_level'],
            'first_name' => $payload['first_name'],
            'middle_name' => $payload['middle_name'],
            'last_name' => $payload['last_name'],
            'email_address' => $payload['email'],
        ]);
    }

    public function test_self_registration_with_mission_id_creates_attendee_with_mission(): void
    {
        $organization = Organization::factory()->create();
        $union = Union::factory()->for($organization)->create();
        $mission = Mission::factory()->for($union)->for($organization)->create();

        $payload = $this->validRegistrationPayload([
            'organization_id' => $organization->id,
            'union_id' => $union->id,
            'mission_id' => $mission->id,
            'organization_level' => OrganizationLevel::Mission->value,
        ]);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated();
        $user = User::where('email', $payload['email'])->first();

        $this->assertDatabaseHas('attendees', [
            'user_id' => $user->id,
            'mission_id' => $mission->id,
            'organization_level' => OrganizationLevel::Mission->value,
        ]);
    }

    public function test_invite_token_registration_overwrites_attendee_org_fields(): void
    {
        $organization = Organization::factory()->create();
        $union = Union::factory()->for($organization)->create();
        $mission = Mission::factory()->for($union)->for($organization)->create();

        // Create an attendee with different org data
        $oldOrganization = Organization::factory()->create();
        $oldUnion = Union::factory()->for($oldOrganization)->create();
        $attendee = Attendee::factory()->for($oldOrganization)->create([
            'email_address' => 'test@example.com',
            'union_id' => $oldUnion->id,
            'organization_level' => OrganizationLevel::Union->value,
            'invite_token' => Attendee::generateUniqueInviteToken(),
        ]);

        $payload = $this->validRegistrationPayload([
            'email' => 'test@example.com',
            'organization_id' => $organization->id,
            'union_id' => $union->id,
            'mission_id' => $mission->id,
            'organization_level' => OrganizationLevel::Mission->value,
            'invite_token' => $attendee->invite_token,
        ]);

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated();
        $user = User::where('email', $payload['email'])->first();

        // Verify the attendee was updated with new org fields, not overwritten
        $attendee->refresh();
        $this->assertSame($user->id, $attendee->user_id);
        $this->assertSame($organization->id, $attendee->organization_id);
        $this->assertSame($union->id, $attendee->union_id);
        $this->assertSame($mission->id, $attendee->mission_id);
        $this->assertSame(OrganizationLevel::Mission->value, $attendee->organization_level->value);
        $this->assertNull($attendee->invite_token);
    }
}
