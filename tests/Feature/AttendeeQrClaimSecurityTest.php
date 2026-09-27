<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Organization;
use App\Models\Union;
use App\Services\AttendeeQrClaimService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AttendeeQrClaimSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_claim_attempt_limits_are_keyed_by_registration_and_claim(): void
    {
        Cache::flush();
        $claims = new AttendeeQrClaimService;
        $registration = new EventRegistration;
        $registration->id = 42;

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->assertTrue($claims->allowVerificationAttempt(42));
        }
        $this->assertFalse($claims->allowVerificationAttempt(42));
        $this->assertTrue($claims->allowVerificationAttempt(43));

        $claimToken = $claims->createClaim($registration);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->assertTrue($claims->allowEmailCodeSend($claimToken));
        }
        $this->assertFalse($claims->allowEmailCodeSend($claimToken));

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->assertTrue($claims->allowClaimCompletion($claimToken));
        }
        $this->assertFalse($claims->allowClaimCompletion($claimToken));
    }

    public function test_claim_updates_do_not_extend_the_original_expiry(): void
    {
        Cache::flush();
        $claims = new AttendeeQrClaimService;
        $registration = new EventRegistration;
        $registration->id = 99;
        $claimToken = $claims->createClaim($registration);
        $originalExpiry = $claims->claim($claimToken)['expires_at'];

        $this->travel(10)->minutes();
        $claims->storeEmailCode($claimToken, 'person@example.com', '123456');
        $this->assertSame($originalExpiry, $claims->claim($claimToken)['expires_at']);

        $this->travel(6)->minutes();
        $this->assertNull($claims->claim($claimToken));
    }

    public function test_claim_endpoint_enforces_per_registration_limit_across_ip_addresses(): void
    {
        Cache::flush();
        $organization = Organization::factory()->create();
        $union = Union::factory()->for($organization)->create();
        $attendee = Attendee::factory()->create([
            'organization_id' => $organization->id,
            'union_id' => $union->id,
            'first_name' => 'Jamie',
            'last_name' => 'Rivera',
            'mission_id' => null,
        ]);
        $registration = EventRegistration::factory()
            ->for(Event::factory()->for($organization))
            ->for($attendee)
            ->create();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$attempt])
                ->postJson('/api/v1/public/attendee-claims/verify', [
                    'qr_token' => $registration->qr_token,
                    'first_name' => 'Wrong',
                    'last_name' => 'Name',
                    'union_id' => $union->id,
                    'mission_id' => null,
                ])->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.11'])
            ->postJson('/api/v1/public/attendee-claims/verify', [
                'qr_token' => $registration->qr_token,
                'first_name' => 'Jamie',
                'last_name' => 'Rivera',
                'union_id' => $union->id,
                'mission_id' => null,
            ])->assertStatus(422)->assertJsonValidationErrors(['details']);
    }

    public function test_wrong_email_codes_cannot_be_reset_by_sending_another_code(): void
    {
        Cache::flush();
        $claims = new AttendeeQrClaimService;
        $registration = new EventRegistration;
        $registration->id = 100;
        $claimToken = $claims->createClaim($registration);
        $claims->storeEmailCode($claimToken, 'person@example.com', '123456');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->assertFalse($claims->verifyEmailCode($claimToken, '000000'));
        }

        $claims->storeEmailCode($claimToken, 'person@example.com', '654321');
        $this->assertFalse($claims->verifyEmailCode($claimToken, '654321'));
    }
}
