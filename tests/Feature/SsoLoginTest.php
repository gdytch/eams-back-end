<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\Organization;
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
    public function brand_new_user_without_invite_token_creates_attendee(): void
    {
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
        $this->assertNull($user->organization_id);
        $this->assertNotNull($user->email_verified_at);

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
        $user = User::factory()->create(['email' => 'existing@example.com']);

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
    public function invalid_invite_token_ignores_it(): void
    {
        $socialiteUser = $this->createSocialiteUser('google', '12345', 'user@example.com', 'User Name');

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

        $response->assertCreated();

        $user = User::where('email', 'user@example.com')->first();
        $this->assertNull($user->organization_id);
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
