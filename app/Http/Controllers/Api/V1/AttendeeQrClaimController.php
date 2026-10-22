<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteQrClaimRequest;
use App\Http\Requests\QrClaimStatusRequest;
use App\Http\Requests\SendQrClaimEmailCodeRequest;
use App\Http\Requests\VerifyQrClaimEmailRequest;
use App\Http\Requests\VerifyQrClaimRequest;
use App\Http\Resources\UserResource;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AttendeeQrClaimEmailCodeNotification;
use App\Services\AttendeeLinkingService;
use App\Services\AttendeeQrClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AttendeeQrClaimController extends Controller
{
    public function status(QrClaimStatusRequest $request): JsonResponse
    {
        $attendee = $this->attendee($request->validated('qr_token'));

        if ($attendee === null) {
            return response()->json(['message' => 'This ID card link is unavailable.'], 404);
        }

        $status = match (true) {
            $attendee->user_id !== null => 'claimed',
            filled($attendee->email_address) => 'unavailable',
            default => 'unclaimed',
        };

        return response()->json(['status' => $status]);
    }

    public function verify(VerifyQrClaimRequest $request, AttendeeQrClaimService $claims): JsonResponse
    {
        $data = $request->validated();
        $attendee = $this->attendee($data['qr_token']);

        if ($attendee === null || $attendee->user_id !== null || filled($attendee->email_address)) {
            throw ValidationException::withMessages(['qr_token' => 'We could not verify those details. Qrcode not valid or already claimed.']);
        }

        if (! $claims->allowVerificationAttempt($attendee->id)) {
            throw ValidationException::withMessages(['details' => 'Too many attempts. Wait before trying again.']);
        }

        if (! $claims->matches($attendee, $data['first_name'], $data['last_name'], $data['union_id'], $data['mission_id'])) {
            throw ValidationException::withMessages(['details' => 'We could not verify those details. Information provided does not match our records. QR code may belong to someone else.']);
        }

        $claimToken = $claims->createClaim($attendee);

        return response()->json([
            'claim_token' => $claimToken,
            'requires_email' => true,
        ]);
    }

    public function sendEmailCode(SendQrClaimEmailCodeRequest $request, AttendeeQrClaimService $claims): JsonResponse
    {
        $data = $request->validated();
        $claim = $claims->claim($data['claim_token']);

        if ($claim === null) {
            throw ValidationException::withMessages(['claim_token' => 'Your verification session has expired. Scan your ID card again.']);
        }

        $attendee = Attendee::query()->find($claim['attendee_id']);
        if ($attendee === null || $attendee->user_id !== null || filled($attendee->email_address)) {
            throw ValidationException::withMessages(['claim_token' => 'Your verification session is no longer available.']);
        }

        if (! $claims->allowEmailCodeSend($data['claim_token'])) {
            throw ValidationException::withMessages(['claim_token' => 'Too many verification codes requested. Scan your ID card again later.']);
        }

        $code = (string) random_int(100000, 999999);
        $claims->storeEmailCode($data['claim_token'], $data['email'], $code);
        Notification::route('mail', $data['email'])->notify(new AttendeeQrClaimEmailCodeNotification($code));

        return response()->json(['message' => 'Verification code sent.']);
    }

    public function verifyEmailCode(VerifyQrClaimEmailRequest $request, AttendeeQrClaimService $claims): JsonResponse
    {
        $data = $request->validated();

        if (! $claims->verifyEmailCode($data['claim_token'], $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Invalid or expired verification code.']);
        }

        return response()->json(['message' => 'Email verified.']);
    }

    public function complete(CompleteQrClaimRequest $request, AttendeeQrClaimService $claims, AttendeeLinkingService $linking): JsonResponse
    {
        $data = $request->validated();
        $claim = $claims->claim($data['claim_token']);

        if ($claim === null) {
            throw ValidationException::withMessages(['claim_token' => 'Your verification session has expired. Scan your ID card again.']);
        }

        if (! $claims->allowClaimCompletion($data['claim_token'])) {
            throw ValidationException::withMessages(['claim_token' => 'Too many attempts. Scan your ID card again.']);
        }

        $result = DB::transaction(function () use ($claim, $data, $linking) {
            $attendee = Attendee::withoutGlobalScopes()->lockForUpdate()->find($claim['attendee_id']);
            if ($attendee === null || $attendee->user_id !== null || filled($attendee->email_address)) {
                throw ValidationException::withMessages(['claim_token' => 'This attendee already has an account.']);
            }

            if (! ($claim['email_verified'] ?? false) || blank($claim['email'] ?? null)) {
                throw ValidationException::withMessages(['claim_token' => 'Verify your email before creating your account.']);
            }

            $email = $claim['email'];
            if ($linking->emailTakenInEvent($email, $attendee)) {
                throw ValidationException::withMessages(['email' => 'This email address is already registered for this event. Sign up with it and verify it instead.']);
            }

            if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'This email address already has an account.']);
            }

            $user = User::create([
                'name' => trim(collect([$attendee->first_name, $attendee->middle_name, $attendee->last_name])->filter()->implode(' ')),
                'email' => $email,
                'password' => $data['password'],
                'first_name' => $attendee->first_name,
                'middle_name' => $attendee->middle_name,
                'last_name' => $attendee->last_name,
                'role' => UserRole::Attendee,
                'email_verified_at' => now(),
            ]);

            $attendee->update([
                'user_id' => $user->id,
                'email_address' => $email,
                'invite_token' => null,
            ]);

            AuditLog::record('attendee.qr_claimed', $attendee, ['user_id' => $user->id]);
            $linking->linkVerifiedEmail($user);

            return [
                'token' => $user->createToken('api')->plainTextToken,
                'user' => UserResource::make($user->load('attendee')),
            ];
        });

        $claims->forgetClaim($data['claim_token']);

        return response()->json($result, 201);
    }

    private function attendee(string $qrToken): ?Attendee
    {
        return Attendee::query()
            ->where('qr_token', $qrToken)
            ->first();
    }
}
