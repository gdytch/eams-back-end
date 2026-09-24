<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
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
    public function attendee_can_claim_an_account_after_matching_details(): void
    {
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration(['email_address' => 'una@example.com']);

        $claimToken = $this->verify($registration, $attendee, $union, $mission)->json('claim_token');

        $response = $this->postJson('/api/v1/public/attendee-claims/complete', [
            'claim_token' => $claimToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::where('email', 'una@example.com')->firstOrFail();
        $this->assertSame($user->id, $attendee->fresh()->user_id);
        $this->assertSame($registration->qr_token, $registration->fresh()->qr_token);
        $response->assertJsonPath('user.attendee.id', $attendee->id);
    }

    #[Test]
    public function claim_rejects_wrong_details_and_accepts_no_mission(): void
    {
        [$attendee, $union, , $registration] = $this->claimableRegistration([
            'email_address' => 'no-mission@example.com',
            'mission_id' => null,
        ]);

        $this->postJson('/api/v1/public/attendee-claims/verify', [
            'qr_token' => $registration->qr_token,
            'first_name' => 'Wrong',
            'last_name' => $attendee->last_name,
            'union_id' => $union->id,
            'mission_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['details']);

        $this->verify($registration, $attendee, $union, null)
            ->assertOk()
            ->assertJsonPath('requires_email', false);
    }

    #[Test]
    public function attendee_without_an_email_must_verify_email_before_claiming(): void
    {
        Notification::fake();
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration(['email_address' => null]);
        $claimToken = $this->verify($registration, $attendee, $union, $mission)->json('claim_token');

        $this->postJson('/api/v1/public/attendee-claims/complete', [
            'claim_token' => $claimToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable();

        $code = null;
        $this->postJson('/api/v1/public/attendee-claims/email-code', [
            'claim_token' => $claimToken,
            'email' => 'new@example.com',
        ])->assertOk();

        Notification::assertSentOnDemand(AttendeeQrClaimEmailCodeNotification::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $this->postJson('/api/v1/public/attendee-claims/verify-email-code', [
            'claim_token' => $claimToken,
            'code' => $code,
        ])->assertOk();

        $this->postJson('/api/v1/public/attendee-claims/complete', [
            'claim_token' => $claimToken,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $this->assertSame('new@example.com', $attendee->fresh()->email_address);
    }

    #[Test]
    public function sso_claim_requires_the_attendee_email_and_links_the_existing_attendee(): void
    {
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration(['email_address' => 'una@example.com']);
        $claimToken = $this->verify($registration, $attendee, $union, $mission)->json('claim_token');
        $this->mockSocialiteUser('una@example.com');

        $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid-token',
            'claim_token' => $claimToken,
        ])->assertCreated()->assertJsonPath('user.attendee.id', $attendee->id);

        $user = User::where('email', 'una@example.com')->firstOrFail();
        $this->assertSame($user->id, $attendee->fresh()->user_id);
        $this->assertTrue(UserIdentity::where('user_id', $user->id)->exists());
    }

    #[Test]
    public function sso_claim_rejects_a_provider_email_that_does_not_match_the_attendee(): void
    {
        [$attendee, $union, $mission, $registration] = $this->claimableRegistration(['email_address' => 'una@example.com']);
        $claimToken = $this->verify($registration, $attendee, $union, $mission)->json('claim_token');
        $this->mockSocialiteUser('other@example.com');

        $this->postJson('/api/v1/auth/sso/google', [
            'token' => 'valid-token',
            'claim_token' => $claimToken,
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertNull($attendee->fresh()->user_id);
    }

    /** @return array{Attendee, Union, Mission, EventRegistration} */
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
            ...$attendeeAttributes,
        ]);
        $registration = EventRegistration::factory()->for(Event::factory()->for($organization))->for($attendee)->create([
            'qr_token' => 'https://events.example.test/attendee/unique-qr-value',
        ]);

        return [$attendee, $union, $mission, $registration];
    }

    private function verify(EventRegistration $registration, Attendee $attendee, Union $union, ?Mission $mission)
    {
        return $this->postJson('/api/v1/public/attendee-claims/verify', [
            'qr_token' => $registration->qr_token,
            'first_name' => strtolower($attendee->first_name),
            'last_name' => strtoupper($attendee->last_name),
            'union_id' => $union->id,
            'mission_id' => $mission?->id,
        ]);
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
