<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthRegisterTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_sign_up_with_an_attendee_email_does_not_link_until_verified(): void
    {
        $event = Event::factory()->create();
        $attendee = Attendee::factory()->for($event)->create(['email_address' => 'claim@example.com']);

        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'claim@example.com',
            'invite_token' => $event->invite_token,
        ]));

        $response->assertCreated();
        $this->assertNull($attendee->fresh()->user_id);
        $this->assertNull(User::find($response->json('user.id'))->email_verified_at);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'attendee.linked']);
    }

    public function test_verifying_the_email_links_every_attendee_with_that_email_across_events(): void
    {
        [$first, $second, $third] = Event::factory()->count(3)->create()->all();
        $a = Attendee::factory()->for($first)->create(['email_address' => 'Multi@Example.com']);
        $b = Attendee::factory()->for($second)->create(['email_address' => 'multi@example.com']);
        $other = Attendee::factory()->for($third)->create(['email_address' => 'someone-else@example.com']);
        $user = User::factory()->unverified()->create(['email' => 'multi@example.com', 'role' => UserRole::Attendee]);
        $existing = Attendee::factory()->for($second)->create(['user_id' => $user->id, 'email_address' => 'elsewhere@example.com']);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->getJson($url)->assertOk();

        $this->assertSame($user->id, $a->fresh()->user_id);
        $this->assertNull($b->fresh()->user_id, 'the user already has an Attendee in that event');
        $this->assertNull($other->fresh()->user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendee.linked', 'auditable_id' => $a->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'attendee.linked', 'auditable_id' => $b->id]);
        $this->assertNotNull($existing->fresh());
    }

    public function test_attendee_invite_link_verifies_the_email_and_links(): void
    {
        $attendee = Attendee::factory()->for(Event::factory()->create())->create([
            'email_address' => 'invited@example.com',
            'invite_token' => Attendee::generateUniqueInviteToken(),
            'invited_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'invited@example.com',
            'invite_token' => $attendee->invite_token,
        ]));

        $response->assertCreated();
        $user = User::find($response->json('user.id'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->organization_id);
        $this->assertSame($user->id, $attendee->fresh()->user_id);
        $this->assertNull($attendee->fresh()->invite_token);
    }

    public function test_attendee_invite_link_for_another_email_does_not_verify(): void
    {
        $attendee = Attendee::factory()->for(Event::factory()->create())->create([
            'email_address' => 'invited@example.com',
            'invite_token' => Attendee::generateUniqueInviteToken(),
            'invited_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'other@example.com',
            'invite_token' => $attendee->invite_token,
        ]));

        $response->assertCreated();
        $this->assertNull(User::find($response->json('user.id'))->email_verified_at);
        $this->assertNull($attendee->fresh()->user_id);
    }

    public function test_unknown_invite_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload(['invite_token' => 'nope']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invite_token']);
    }

    public function test_expired_personal_attendee_invite_cannot_be_claimed(): void
    {
        $attendee = Attendee::factory()->create([
            'email_address' => 'expired@example.com',
            'invite_token' => Attendee::generateUniqueInviteToken(),
            'invited_at' => now()->subDays(31),
        ]);
        $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'expired@example.com',
            'invite_token' => $attendee->invite_token,
        ]))->assertUnprocessable();
        $this->assertNull($attendee->fresh()->user_id);
    }

    private function validRegistrationPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    public function test_user_can_register_with_valid_data(): void
    {
        $payload = $this->validRegistrationPayload();

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);
        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    }

    public function test_registered_user_always_has_attendee_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'jane@example.com',
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
        ]));

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $meResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $meResponse->assertOk();
        $this->assertSame('maria@example.com', $meResponse->json('user.email'));
    }

    public function test_registration_does_not_collect_names(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'email' => 'handle@example.com',
            'first_name' => 'Ignored',
            'last_name' => 'Name',
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'handle@example.com',
            'name' => 'handle',
            'first_name' => null,
            'last_name' => null,
        ]);
    }

    public function test_sign_up_creates_the_user_only(): void
    {
        $payload = $this->validRegistrationPayload();

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated()->assertJsonMissingPath('user.attendee');
        $user = User::where('email', $payload['email'])->first();
        $this->assertNull($user->organization_id);
        $this->assertDatabaseCount('attendees', 0);
    }

    public function test_sign_up_ignores_territory_fields(): void
    {
        $organization = Organization::factory()->create();

        $this->postJson('/api/v1/auth/register', $this->validRegistrationPayload([
            'organization_id' => $organization->id,
        ]))->assertCreated();

        $this->assertNull(User::where('email', 'john@example.com')->value('organization_id'));
        $this->assertDatabaseCount('attendees', 0);
    }
}
