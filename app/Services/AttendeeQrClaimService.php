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
        $claim = Cache::get($this->cacheKey($claimToken));

        return $claim !== null && ($claim['expires_at'] ?? 0) > now()->timestamp ? $claim : null;
    }

    public function createClaim(EventRegistration $registration): string
    {
        $claimToken = Str::random(64);

        Cache::put($this->cacheKey($claimToken), [
            'registration_id' => $registration->id,
            'email' => null,
            'email_verified' => false,
            'expires_at' => now()->addMinutes(self::CLAIM_TTL_MINUTES)->timestamp,
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
        Cache::lock('attendee-qr-claim-lock:'.hash('sha256', $claimToken), 5)->block(3, function () use ($claimToken, $email, $code): void {
            $claim = $this->claim($claimToken);

            if ($claim === null) {
                return;
            }

            $claim['email'] = $email;
            $claim['email_code'] = Hash::make($code);
            $claim['email_verified'] = false;
            $claim['email_code_attempts'] ??= 0;

            $this->storeClaim($claimToken, $claim);
        });
    }

    public function verifyEmailCode(string $claimToken, string $code): bool
    {
        return Cache::lock('attendee-qr-claim-lock:'.hash('sha256', $claimToken), 5)->block(3, function () use ($claimToken, $code): bool {
            $claim = $this->claim($claimToken);
            if ($claim === null) {
                return false;
            }

            if (($claim['email_code_attempts'] ?? 0) >= 5 || blank($claim['email_code'] ?? null) || ! Hash::check($code, $claim['email_code'])) {
                $claim['email_code_attempts'] = ($claim['email_code_attempts'] ?? 0) + 1;
                $this->storeClaim($claimToken, $claim);

                return false;
            }

            $claim['email_verified'] = true;
            unset($claim['email_code']);
            $this->storeClaim($claimToken, $claim);

            return true;
        });
    }

    public function allowVerificationAttempt(int $registrationId): bool
    {
        $key = 'attendee-qr-verify:'.$registrationId;
        Cache::add($key, 0, now()->addHour());
        $attempts = Cache::increment($key);

        return $attempts <= 10;
    }

    public function allowEmailCodeSend(string $claimToken): bool
    {
        $key = 'attendee-qr-email-send:'.hash('sha256', $claimToken);
        Cache::add($key, 0, now()->addMinutes(self::CLAIM_TTL_MINUTES));
        $attempts = Cache::increment($key);

        return $attempts <= 3;
    }

    public function allowClaimCompletion(string $claimToken): bool
    {
        $key = 'attendee-qr-complete:'.hash('sha256', $claimToken);
        Cache::add($key, 0, now()->addMinutes(self::CLAIM_TTL_MINUTES));

        return Cache::increment($key) <= 10;
    }

    private function cacheKey(string $claimToken): string
    {
        return 'attendee-qr-claim:'.hash('sha256', $claimToken);
    }

    /** @param array<string, mixed> $claim */
    private function storeClaim(string $claimToken, array $claim): void
    {
        $seconds = ($claim['expires_at'] ?? 0) - now()->timestamp;
        if ($seconds > 0) {
            Cache::put($this->cacheKey($claimToken), $claim, now()->addSeconds($seconds));
        }
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->squish()->lower()->toString();
    }
}
