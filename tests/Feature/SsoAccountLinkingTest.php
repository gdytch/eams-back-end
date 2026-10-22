<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_unverified_email_creates_the_user_but_does_not_link_an_attendee(): void
    {
        $attendee = Attendee::factory()->for(Event::factory()->create())->create(['email_address' => 'person@gmail.com', 'user_id' => null]);
        $this->mockGoogleUser('person@gmail.com', false);

        $this->postJson('/api/v1/auth/sso/google', ['token' => 'provider-token'])
            ->assertCreated()
            ->assertJsonPath('user.email_verified', false);

        $this->assertNull($attendee->fresh()->user_id);
    }

    public function test_verified_sso_links_attendees_across_events_at_once(): void
    {
        $first = Attendee::factory()->for(Event::factory()->create())->create(['email_address' => 'person@gmail.com', 'user_id' => null]);
        $second = Attendee::factory()->for(Event::factory()->create())->create(['email_address' => 'person@gmail.com', 'user_id' => null]);
        $this->mockGoogleUser('person@gmail.com', true);

        $response = $this->postJson('/api/v1/auth/sso/google', ['token' => 'provider-token'])->assertCreated();

        $userId = $response->json('user.id');
        $this->assertNull(User::find($userId)->organization_id);
        $this->assertSame($userId, $first->fresh()->user_id);
        $this->assertSame($userId, $second->fresh()->user_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendee.linked', 'auditable_id' => $first->id]);
        $this->assertDatabaseCount('attendees', 2);
    }

    public function test_sso_verifying_a_squatted_sign_up_revokes_its_password_and_tokens(): void
    {
        $squatter = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Sam',
            'last_name' => 'Squat',
            'email' => 'victim@gmail.com',
            'password' => 'squatter-pass',
            'password_confirmation' => 'squatter-pass',
        ])->assertCreated();
        $this->mockGoogleUser('victim@gmail.com', true);

        $this->postJson('/api/v1/auth/sso/google', ['token' => 'provider-token'])->assertOk();

        $user = User::where('email', 'victim@gmail.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertFalse(Hash::check('squatter-pass', $user->password));
        $this->assertNotNull($squatter->json('token'));
    }

    public function test_sso_sign_in_creates_the_user_only(): void
    {
        $this->mockGoogleUser('fresh@gmail.com', true);

        $this->postJson('/api/v1/auth/sso/google', ['token' => 'provider-token'])->assertCreated();

        $this->assertDatabaseCount('attendees', 0);
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
