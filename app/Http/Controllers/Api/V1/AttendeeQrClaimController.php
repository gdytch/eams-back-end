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
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\AttendeeQrClaimEmailCodeNotification;
use App\Services\AttendeeQrClaimService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AttendeeQrClaimController extends Controller
{
    public function status(QrClaimStatusRequest $request): JsonResponse
    {
        $registration = $this->registration($request->validated('qr_token'));

        if ($registration === null) {
            return response()->json(['message' => 'This ID card link is unavailable.'], 404);
        }

        return response()->json([
            'status' => $registration->attendee->user_id === null ? 'unclaimed' : 'claimed',
        ]);
    }

    public function verify(VerifyQrClaimRequest $request, AttendeeQrClaimService $claims): JsonResponse
    {
        $data = $request->validated();
        $registration = $this->registration($data['qr_token']);

        if ($registration === null || $registration->attendee->user_id !== null) {
            throw ValidationException::withMessages(['qr_token' => 'We could not verify those details. Qrcode not valid or already claimed.']);
        }

        if (! $claims->matches($registration->attendee, $data['first_name'], $data['last_name'], $data['union_id'], $data['mission_id'])) {
            throw ValidationException::withMessages(['details' => 'We could not verify those details. Information provided does not match our records. QR code may belong to someone else.']);
        }

        $claimToken = $claims->createClaim($registration);

        return response()->json([
            'claim_token' => $claimToken,
            'requires_email' => blank($registration->attendee->email_address),
        ]);
    }

    public function sendEmailCode(SendQrClaimEmailCodeRequest $request, AttendeeQrClaimService $claims): JsonResponse
    {
        $data = $request->validated();
        $claim = $claims->claim($data['claim_token']);

        if ($claim === null) {
            throw ValidationException::withMessages(['claim_token' => 'Your verification session has expired. Scan your ID card again.']);
        }

        $registration = EventRegistration::query()->with('attendee')->find($claim['registration_id']);
        if ($registration === null || $registration->attendee->user_id !== null || filled($registration->attendee->email_address)) {
            throw ValidationException::withMessages(['claim_token' => 'Your verification session is no longer available.']);
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

    public function complete(CompleteQrClaimRequest $request, AttendeeQrClaimService $claims): JsonResponse
    {
        $data = $request->validated();
        $claim = $claims->claim($data['claim_token']);

        if ($claim === null) {
            throw ValidationException::withMessages(['claim_token' => 'Your verification session has expired. Scan your ID card again.']);
        }

        $result = DB::transaction(function () use ($claim, $data) {
            $registration = EventRegistration::query()->lockForUpdate()->find($claim['registration_id']);
            if ($registration === null) {
                throw ValidationException::withMessages(['claim_token' => 'Your verification session is no longer available.']);
            }

            $attendee = Attendee::withoutGlobalScopes()->lockForUpdate()->find($registration->attendee_id);
            if ($attendee === null || $attendee->user_id !== null) {
                throw ValidationException::withMessages(['claim_token' => 'This attendee already has an account.']);
            }

            $email = $attendee->email_address;
            $emailVerified = false;
            if (blank($email)) {
                if (! ($claim['email_verified'] ?? false) || blank($claim['email'] ?? null)) {
                    throw ValidationException::withMessages(['claim_token' => 'Verify your email before creating your account.']);
                }

                $email = $claim['email'];
                $emailVerified = true;
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
                'organization_id' => $attendee->organization_id,
                'email_verified_at' => $emailVerified ? now() : null,
            ]);

            $attendee->update([
                'user_id' => $user->id,
                'email_address' => $email,
                'invite_token' => null,
            ]);

            AuditLog::record('attendee.qr_claimed', $attendee, ['user_id' => $user->id]);

            if (! $emailVerified) {
                event(new Registered($user));
            }

            return [
                'token' => $user->createToken('api')->plainTextToken,
                'user' => UserResource::make($user->load('attendee')),
            ];
        });

        $claims->forgetClaim($data['claim_token']);

        return response()->json($result, 201);
    }

    private function registration(string $qrToken): ?EventRegistration
    {
        return EventRegistration::query()
            ->with('attendee')
            ->where('qr_token', $qrToken)
            ->first();
    }
}
