<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Mission;
use App\Models\Organization;
use App\Models\Union;
use App\Models\User;
use App\Models\UserIdentity;
use App\Notifications\AttendeeQrClaimEmailCodeNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AttendeeQrClaimTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function status_uses_the_complete_qr_url_stored_on_the_registration(): void
    {
        [, , , $registration] = $this->claimableRegistration();

        $this->postJson('/api/v1/public/attendee-claims/status', ['qr_token' => $registration->qr_token])
            ->assertOk()
            ->assertJsonPath('status', 'unclaimed');

        $this->postJson('/api/v1/public/attendee-claims/status', ['qr_token' => basename($registration->qr_token)])
            ->assertNotFound();
    }

    #[Test]
    public function status_is_unavailable_for_an_attendee_that_has_an_email(): void
    {
        [, , , $registration] = $this->claimableRegistration(['email_address' => 'una@example.com']);

        $this->postJson('/api/v1/public/attendee-claims/status', ['qr_token' => $registration->qr_token])
            ->assertOk()
            ->assertJsonPath('status', 'unavailable');
    }

    #[Test]
    public function claim_is_refused_for_an_attendee_that_has_an_email(): void
    {
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration(['email_address' => 'una@example.com']);

        $this->verify($registration, $attendee, $union, $mission)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['qr_token']);

        $this->assertNull($attendee->fresh()->user_id);
    }

    #[Test]
    public function claim_rejects_wrong_details_and_accepts_no_mission(): void
    {
        [$attendee, $union, , $registration] = $this->claimableRegistration(['mission_id' => null]);

        $this->postJson('/api/v1/public/attendee-claims/verify', [
            'qr_token' => $registration->qr_token,
            'first_name' => 'Wrong',
            'last_name' => $attendee->last_name,
            'union_id' => $union->id,
            'mission_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['details']);

        $this->verify($registration, $attendee, $union, null)
            ->assertOk()
            ->assertJsonPath('requires_email', true);
    }

    #[Test]
    public function attendee_must_verify_email_before_claiming(): void
    {
        Notification::fake();
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration();
        $claimToken = $this->verify($registration, $attendee, $union, $mission)->json('claim_token');

        $this->postJson('/api/v1/public/attendee-claims/complete', [
            'claim_token' => $claimToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable();

        $this->assertNull($attendee->fresh()->user_id);
    }

    #[Test]
    public function verified_code_fills_in_the_email_and_links_every_attendee_with_that_email(): void
    {
        Notification::fake();
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration();
        $otherEvent = Attendee::factory()->for(Event::factory()->create())->create(['email_address' => 'New@Example.com']);
        $claimToken = $this->verify($registration, $attendee, $union, $mission)->json('claim_token');
        $this->verifyEmail($claimToken, 'new@example.com');

        $response = $this->postJson('/api/v1/public/attendee-claims/complete', [
            'claim_token' => $claimToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->organization_id);
        $this->assertSame('new@example.com', $attendee->fresh()->email_address);
        $this->assertSame($user->id, $attendee->fresh()->user_id);
        $this->assertSame($user->id, $otherEvent->fresh()->user_id);
        $this->assertSame($registration->qr_token, $registration->fresh()->qr_token);
        $response->assertJsonPath('user.attendee.id', $otherEvent->id);
    }

    #[Test]
    public function claim_is_refused_when_another_attendee_in_the_event_holds_the_email(): void
    {
        Notification::fake();
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration();
        Attendee::factory()->for($registration->event)->create(['email_address' => 'new@example.com']);
        $claimToken = $this->verify($registration, $attendee, $union, $mission)->json('claim_token');

        $this->postJson('/api/v1/public/attendee-claims/email-code', ['claim_token' => $claimToken, 'email' => 'new@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    #[Test]
    public function sso_claim_uses_the_verified_email_and_links_the_scanned_attendee(): void
    {
        Notification::fake();
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration();
        $claimToken = $this->verify($registration, $attendee, $union, $mission)->json('claim_token');
        $this->verifyEmail($claimToken, 'una@example.com');
        $this->mockSocialiteUser('una@example.com');

        $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid-token',
            'claim_token' => $claimToken,
        ])->assertCreated();

        $user = User::where('email', 'una@example.com')->firstOrFail();
        $this->assertSame($user->id, $attendee->fresh()->user_id);
        $this->assertSame('una@example.com', $attendee->fresh()->email_address);
        $this->assertTrue(UserIdentity::where('user_id', $user->id)->exists());
    }

    #[Test]
    public function sso_claim_rejects_a_provider_email_that_was_not_verified(): void
    {
        Notification::fake();
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration();
        $claimToken = $this->verify($registration, $attendee, $union, $mission)->json('claim_token');
        $this->verifyEmail($claimToken, 'una@example.com');
        $this->mockSocialiteUser('other@example.com');

        $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid-token',
            'claim_token' => $claimToken,
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertNull($attendee->fresh()->user_id);
    }

    #[Test]
    public function sso_claim_is_refused_when_the_attendee_gained_an_email_after_the_scan(): void
    {
        Notification::fake();
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration();
        $claimToken = $this->verify($registration, $attendee, $union, $mission)->json('claim_token');
        $this->verifyEmail($claimToken, 'una@example.com');
        $attendee->update(['email_address' => 'admin-set@example.com']);
        $this->mockSocialiteUser('una@example.com');

        $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid-token',
            'claim_token' => $claimToken,
        ])->assertUnprocessable()->assertJsonValidationErrors(['claim_token']);

        $this->assertNull($attendee->fresh()->user_id);
    }

    /** @return array{Attendee, Union, Mission, Attendee} */
    private function claimableRegistration(array $attendeeAttributes = []): array
    {
        $organization = Organization::factory()->create();
        $union = Union::factory()->for($organization)->create();
        $mission = Mission::factory()->for($organization)->for($union)->create();
        $attendee = Attendee::factory()->for($organization)->create([
            'first_name' => 'Una',
            'last_name' => 'Abernathy',
            'union_id' => $union->id,
            'mission_id' => $mission->id,
            'email_address' => null,
            ...$attendeeAttributes,
        ]);
        $registration = $this->registerInEvent(Event::factory()->for($organization), $attendee, [
            'qr_token' => 'https://events.example.test/attendee/unique-qr-value',
        ]);

        return [$attendee, $union, $mission, $registration];
    }

    private function verify(Attendee $registration, Attendee $attendee, Union $union, ?Mission $mission)
    {
        return $this->postJson('/api/v1/public/attendee-claims/verify', [
            'qr_token' => $registration->qr_token,
            'first_name' => strtolower($attendee->first_name),
            'last_name' => strtoupper($attendee->last_name),
            'union_id' => $union->id,
            'mission_id' => $mission?->id,
        ]);
    }

    private function verifyEmail(string $claimToken, string $email): void
    {
        $code = null;
        $this->postJson('/api/v1/public/attendee-claims/email-code', ['claim_token' => $claimToken, 'email' => $email])->assertOk();

        Notification::assertSentOnDemand(AttendeeQrClaimEmailCodeNotification::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $this->postJson('/api/v1/public/attendee-claims/verify-email-code', ['claim_token' => $claimToken, 'code' => $code])->assertOk();
    }

    private function mockSocialiteUser(string $email): void
    {
        $socialiteUser = new SocialiteUser;
        $socialiteUser->map(['id' => 'google-claim-user', 'email' => $email, 'name' => 'Different Name']);

        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($driver = \Mockery::mock());
        $driver->shouldReceive('stateless')->once()->andReturnSelf();
        $driver->shouldReceive('userFromToken')->with('valid-token')->once()->andReturn($socialiteUser);
    }
}
