<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\EventRegistration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AttendeeQrClaimService
{
    private const CLAIM_TTL_MINUTES = 15;

    /** @return array<string, mixed>|null */
    public function claim(string $claimToken): ?array
    {
        return Cache::get($this->cacheKey($claimToken));
    }

    public function createClaim(EventRegistration $registration): string
    {
        $claimToken = Str::random(64);

        Cache::put($this->cacheKey($claimToken), [
            'registration_id' => $registration->id,
            'email' => null,
            'email_verified' => false,
        ], now()->addMinutes(self::CLAIM_TTL_MINUTES));

        return $claimToken;
    }

    public function forgetClaim(string $claimToken): void
    {
        Cache::forget($this->cacheKey($claimToken));
    }

    public function matches(Attendee $attendee, string $firstName, string $lastName, int $unionId, ?int $missionId): bool
    {
        return $this->normalize($attendee->first_name) === $this->normalize($firstName)
            && $this->normalize($attendee->last_name) === $this->normalize($lastName)
            && $attendee->union_id === $unionId
            && $attendee->mission_id === $missionId;
    }

    public function storeEmailCode(string $claimToken, string $email, string $code): void
    {
        $claim = $this->claim($claimToken);

        if ($claim === null) {
            return;
        }

        $claim['email'] = $email;
        $claim['email_code'] = Hash::make($code);
        $claim['email_verified'] = false;

        Cache::put($this->cacheKey($claimToken), $claim, now()->addMinutes(self::CLAIM_TTL_MINUTES));
    }

    public function verifyEmailCode(string $claimToken, string $code): bool
    {
        $claim = $this->claim($claimToken);

        if ($claim === null || blank($claim['email_code'] ?? null) || ! Hash::check($code, $claim['email_code'])) {
            return false;
        }

        $claim['email_verified'] = true;
        unset($claim['email_code']);
        Cache::put($this->cacheKey($claimToken), $claim, now()->addMinutes(self::CLAIM_TTL_MINUTES));

        return true;
    }

    private function cacheKey(string $claimToken): string
    {
        return 'attendee-qr-claim:'.hash('sha256', $claimToken);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->squish()->lower()->toString();
    }
}
