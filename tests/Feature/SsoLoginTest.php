<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SsoLoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function brand_new_user_with_email_in_attendee_prioritizes_attendee_organization(): void
    {
        $attendeeOrg = Organization::factory()->create();
        $inviteOrg = Organization::factory()->create();

        $attendee = Attendee::factory()
            ->for($attendeeOrg)
            ->state(['email_address' => 'newuser@example.com'])
            ->create();

        $event = Event::factory()
            ->for($inviteOrg)
            ->state(['status' => EventStatus::Published])
            ->create();

        $socialiteUser = $this->createSocialiteUser('google', '12345', 'newuser@example.com', 'John Doe');

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid_token',
            'invite_token' => $event->invite_token,
        ]);

        $response->assertCreated();

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        // Verify attendee organization is used, not invite_token organization
        $this->assertEquals($attendeeOrg->id, $user->organization_id);

        $attendee->refresh();
        $this->assertEquals($user->id, $attendee->user_id);
    }

    #[Test]
    public function brand_new_user_with_email_in_attendee_creates_user(): void
    {
        $org = Organization::factory()->create();
        $attendee = Attendee::factory()
            ->for($org)
            ->state(['email_address' => 'newuser@example.com'])
            ->create();

        $socialiteUser = $this->createSocialiteUser('google', '12345', 'newuser@example.com', 'John Doe');

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid_token',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure([
            'token',
            'user',
            'is_new_user',
        ]);
        $response->assertJson(['is_new_user' => true]);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals(UserRole::Attendee, $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertEquals($org->id, $user->organization_id);

        // Verify attendee was linked to the user
        $attendee->refresh();
        $this->assertEquals($user->id, $attendee->user_id);
        $this->assertNull($attendee->invite_token);

        $identity = UserIdentity::where('provider', 'google')
            ->where('provider_id', '12345')
            ->first();
        $this->assertNotNull($identity);
        $this->assertEquals($user->id, $identity->user_id);
    }

    #[Test]
    public function brand_new_user_with_valid_invite_token_sets_organization(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()
            ->for($org)
            ->state(['status' => EventStatus::Published])
            ->create();

        $socialiteUser = $this->createSocialiteUser('microsoft', 'user-123', 'attendee@company.com', 'Jane Smith');

        Socialite::shouldReceive('driver')
            ->with('microsoft')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/microsoft', [
            'token' => 'valid_token',
            'invite_token' => $event->invite_token,
        ]);

        $response->assertCreated();

        $user = User::where('email', 'attendee@company.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals($org->id, $user->organization_id);
    }

    #[Test]
    public function existing_user_matched_by_email_links_identity(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['email' => 'existing@example.com', 'organization_id' => null]);
        $attendee = Attendee::factory()
            ->for($org)
            ->state(['email_address' => 'existing@example.com', 'user_id' => null])
            ->create();

        $socialiteUser = $this->createSocialiteUser('facebook', 'fb-user-456', 'existing@example.com', 'Existing User');

        Socialite::shouldReceive('driver')
            ->with('facebook')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/facebook', [
            'token' => 'valid_token',
        ]);

        $response->assertOk();
        $response->assertJson(['is_new_user' => false]);

        $identity = UserIdentity::where('provider', 'facebook')
            ->where('provider_id', 'fb-user-456')
            ->first();
        $this->assertNotNull($identity);
        $this->assertEquals($user->id, $identity->user_id);

        // Verify attendee was linked to the user and organization inherited
        $attendee->refresh();
        $this->assertEquals($user->id, $attendee->user_id);
        $user->refresh();
        $this->assertEquals($org->id, $user->organization_id);
    }

    #[Test]
    public function second_login_with_existing_identity_does_not_create_duplicate(): void
    {
        $user = User::factory()->create();
        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-789',
            'email' => $user->email,
        ]);

        $socialiteUser = $this->createSocialiteUser('google', 'google-789', $user->email, $user->name);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid_token',
        ]);

        $response->assertOk();

        // Ensure only one identity exists for this user
        $identities = UserIdentity::where('user_id', $user->id)
            ->where('provider', 'google')
            ->get();
        $this->assertCount(1, $identities);
    }

    #[Test]
    public function existing_user_can_login_when_legacy_client_sends_null_territory_fields(): void
    {
        $user = User::factory()->create();
        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-null-territory',
            'email' => $user->email,
        ]);

        $socialiteUser = $this->createSocialiteUser('google', 'google-null-territory', $user->email, $user->name);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid_token',
            'organization_id' => null,
            'organization_level' => null,
            'union_id' => null,
            'mission_id' => null,
        ]);

        $response->assertOk();
        $response->assertJson(['is_new_user' => false]);
    }

    #[Test]
    public function existing_user_login_without_territory_does_not_create_attendee(): void
    {
        $user = User::factory()->create(['email' => 'staff@example.com']);
        $socialiteUser = $this->createSocialiteUser('google', 'google-staff', $user->email, $user->name);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid_token',
        ]);

        $response->assertOk();
        $response->assertJson(['is_new_user' => false]);
        $this->assertDatabaseMissing('attendees', ['user_id' => $user->id]);
    }

    #[Test]
    public function unregistered_user_can_self_register_with_sso_territory(): void
    {
        $organization = Organization::factory()->create();
        $union = Union::factory()->for($organization)->create();
        $socialiteUser = $this->createSocialiteUser('google', 'google-self-register', 'self-register@example.com', 'Self Register');

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid_token',
            'organization_id' => $organization->id,
            'organization_level' => 'union',
            'union_id' => $union->id,
            'mission_id' => null,
        ]);

        $response->assertCreated();
        $response->assertJson(['is_new_user' => true]);

        $user = User::where('email', 'self-register@example.com')->firstOrFail();
        $this->assertDatabaseHas('attendees', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'organization_level' => 'union',
            'union_id' => $union->id,
            'mission_id' => null,
        ]);
    }

    #[Test]
    public function unknown_provider_returns_404(): void
    {
        $response = $this->postJson('/api/v1/auth/sso/unknownprovider', [
            'token' => 'valid_token',
        ]);

        $response->assertNotFound();
    }

    #[Test]
    public function provider_throws_exception_returns_401(): void
    {
        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('bad_token')
            ->andThrow(new \Exception('Token verification failed'))
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'bad_token',
        ]);

        $response->assertUnauthorized();
        $response->assertJson(['message' => 'Unable to verify SSO token.']);
    }

    #[Test]
    public function provider_returns_no_email_returns_422(): void
    {
        $socialiteUser = $this->createSocialiteUser('google', '12345', null, 'John Doe');

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid_token',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Email permission is required to sign in.']);
    }

    #[Test]
    public function unregistered_email_without_invite_token_returns_validation_error(): void
    {
        $socialiteUser = $this->createSocialiteUser('google', '12345', 'unregistered@example.com', 'User Name');

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid_token',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function unregistered_email_with_invalid_invite_token_returns_validation_error(): void
    {
        $socialiteUser = $this->createSocialiteUser('google', '12345', 'unregistered@example.com', 'User Name');

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid_token',
            'invite_token' => 'invalid_token_that_does_not_exist',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function unregistered_email_with_valid_invite_token_creates_user(): void
    {
        $org = Organization::factory()->create();
        $event = Event::factory()
            ->for($org)
            ->state(['status' => EventStatus::Published])
            ->create();

        $socialiteUser = $this->createSocialiteUser('google', '12345', 'newattendee@example.com', 'New Attendee');

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($mock = \Mockery::mock())
            ->once();

        $mock->shouldReceive('stateless')
            ->andReturnSelf()
            ->once();

        $mock->shouldReceive('userFromToken')
            ->with('valid_token')
            ->andReturn($socialiteUser)
            ->once();

        $response = $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid_token',
            'invite_token' => $event->invite_token,
        ]);

        $response->assertCreated();
        $response->assertJson(['is_new_user' => true]);

        $user = User::where('email', 'newattendee@example.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals($org->id, $user->organization_id);
    }

    /**
     * Helper to create a mock Socialite user.
     */
    private function createSocialiteUser(string $provider, string $id, ?string $email, ?string $name): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->id = $id;
        $user->name = $name;
        $user->email = $email;

        return $user;
    }
}
