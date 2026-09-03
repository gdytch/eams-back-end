<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthRegisterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'John',
            'middle_name' => 'Paul',
            'last_name' => 'Smith',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);
        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    }

    public function test_registered_user_always_has_attendee_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertSame(UserRole::Attendee->value, $response->json('user.role'));
        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'role' => UserRole::Attendee->value,
        ]);
    }

    public function test_registration_ignores_role_field_in_request(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Admin',
            'last_name' => 'Hacker',
            'email' => 'hacker@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'super_admin', // Try to hack in a role
        ]);

        $response->assertCreated();
        $this->assertSame(UserRole::Attendee->value, $response->json('user.role'));
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Duplicate',
            'last_name' => 'User',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
    }

    public function test_registration_fails_with_mismatched_passwords(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different-password',
        ]);

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
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        // Test that the token works
        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $meResponse->assertOk();
        $this->assertSame('maria@example.com', $meResponse->json('user.email'));
    }

    public function test_registration_stores_name_parts(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'John',
            'middle_name' => 'Michael',
            'last_name' => 'Johnson',
            'email' => 'john.m.johnson@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'john.m.johnson@example.com',
            'first_name' => 'John',
            'middle_name' => 'Michael',
            'last_name' => 'Johnson',
        ]);
    }
}
