<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class SsoAccountLinkingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unverified_google_email_cannot_link_to_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'person@gmail.com']);
        $this->mockGoogleUser('person@gmail.com', false);

        $this->postJson('/api/v1/auth/sso/google', ['token' => 'provider-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('user_identities', ['user_id' => $user->id, 'provider' => 'google']);
    }

    public function test_verified_gmail_can_link_to_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'person@gmail.com']);
        $this->mockGoogleUser('person@gmail.com', true);

        $response = $this->postJson('/api/v1/auth/sso/google', ['token' => 'provider-token']);

        $response->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseHas('user_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'google-subject-1',
        ]);
    }

    public function test_unverified_email_cannot_claim_an_unlinked_attendee_without_an_invite(): void
    {
        Attendee::factory()->create(['email_address' => 'person@gmail.com', 'user_id' => null]);
        $this->mockGoogleUser('person@gmail.com', false);

        $this->postJson('/api/v1/auth/sso/google', ['token' => 'provider-token'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_sso_does_not_reassign_an_attendee_already_linked_to_another_user(): void
    {
        $existingUser = User::factory()->create(['email' => 'person@gmail.com']);
        $otherUser = User::factory()->create(['email' => 'other@example.com']);
        $attendee = Attendee::factory()->create([
            'email_address' => 'person@gmail.com',
            'user_id' => $otherUser->id,
        ]);
        $this->mockGoogleUser('person@gmail.com', true);

        $this->postJson('/api/v1/auth/sso/google', ['token' => 'provider-token'])->assertOk();

        $this->assertSame($otherUser->id, $attendee->fresh()->user_id);
        $this->assertDatabaseHas('user_identities', ['user_id' => $existingUser->id, 'provider_id' => 'google-subject-1']);
    }

    private function mockGoogleUser(string $email, bool $verified): void
    {
        $socialiteUser = new class($email, $verified)
        {
            public function __construct(private string $email, private bool $verified) {}

            public function getEmail(): string
            {
                return $this->email;
            }

            public function getId(): string
            {
                return 'google-subject-1';
            }

            public function getName(): string
            {
                return 'Test User';
            }

            public function getRaw(): array
            {
                return ['verified_email' => $this->verified];
            }
        };

        $driver = new class($socialiteUser)
        {
            public function __construct(private object $user) {}

            public function stateless(): self
            {
                return $this;
            }

            public function userFromToken(string $token): object
            {
                return $this->user;
            }
        };

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
    }
}
