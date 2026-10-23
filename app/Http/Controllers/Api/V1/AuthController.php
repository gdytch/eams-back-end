<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SsoProvider;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptUserInviteRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\SsoLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Attendee;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\AttendeeLinkingService;
use App\Services\AttendeeQrClaimService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::once($request->validated())) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $user = Auth::user();

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => UserResource::make($user),
        ]);
    }

    public function register(RegisterRequest $request, AttendeeLinkingService $linking): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $linking) {
            // Using an Attendee invite link for the invited address verifies that address.
            $inviteVerifiesEmail = $linking->inviteVouchesForEmail($data['invite_token'] ?? null, $data['email']);

            // Names are collected in the registration wizard's Account Info step; until then show the email handle.
            $user = User::create([
                'name' => Str::before($data['email'], '@'),
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => UserRole::Attendee,
                'email_verified_at' => $inviteVerifiesEmail ? now() : null,
            ]);

            $linking->linkVerifiedEmail($user);

            if ($user->email_verified_at === null) {
                event(new Registered($user));
            }

            return response()->json([
                'token' => $user->createToken('api')->plainTextToken,
                'user' => UserResource::make($user),
            ], 201);
        });
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserResource::make($request->user()->load('attendee')),
        ]);
    }

    public function sso(SsoLoginRequest $request, string $provider, AttendeeQrClaimService $claims, AttendeeLinkingService $linking): JsonResponse
    {
        $data = $request->validated();
        $ssoProvider = SsoProvider::tryFrom($provider);
        abort_if($ssoProvider === null, 404);

        try {
            $socialiteUser = Socialite::driver($ssoProvider->value)->stateless()->userFromToken($data['token']);
        } catch (Throwable) {
            return response()->json(['message' => 'Unable to verify SSO token.'], 401);
        }

        abort_if(blank($socialiteUser->getEmail()), 422, 'Email permission is required to sign in.');

        if ($request->filled('claim_token')) {
            return $this->claimWithSso($request->validated('claim_token'), $socialiteUser, $ssoProvider, $claims, $linking);
        }

        return DB::transaction(function () use ($data, $socialiteUser, $ssoProvider, $linking) {
            $providerId = $socialiteUser->getId();
            $wasNewUser = false;

            $identity = UserIdentity::where('provider', $ssoProvider->value)
                ->where('provider_id', $providerId)
                ->first();

            if ($identity !== null) {
                $user = $identity->user;
            } else {
                $user = User::withoutGlobalScopes()
                    ->where('email', $socialiteUser->getEmail())
                    ->first();

                $providerVouchesForEmail = $this->hasAuthoritativeEmail($ssoProvider, $socialiteUser);

                if ($user !== null && ! $providerVouchesForEmail) {
                    throw ValidationException::withMessages([
                        'email' => 'This provider has not verified your email. Sign in with your existing account or reset its password.',
                    ]);
                }

                if ($user === null) {
                    $name = $socialiteUser->getName() ?? Str::before($socialiteUser->getEmail(), '@');

                    // An Attendee invite link for this address verifies it even when the provider does not.
                    $inviteVerifiesEmail = $linking->inviteVouchesForEmail($data['invite_token'] ?? null, $socialiteUser->getEmail());

                    $user = User::create([
                        'name' => $name,
                        'email' => $socialiteUser->getEmail(),
                        'password' => Hash::make(Str::random(40)),
                        'first_name' => Str::before($name, ' ') ?: Str::before($socialiteUser->getEmail(), '@'),
                        'middle_name' => null,
                        'last_name' => Str::contains($name, ' ') ? Str::after($name, ' ') : '',
                        'role' => UserRole::Attendee,
                        'email_verified_at' => $providerVouchesForEmail || $inviteVerifiesEmail ? now() : null,
                    ]);

                    $wasNewUser = true;
                }

                UserIdentity::create([
                    'user_id' => $user->id,
                    'provider' => $ssoProvider->value,
                    'provider_id' => $providerId,
                    'email' => $socialiteUser->getEmail(),
                ]);
            }

            if ($user->email_verified_at === null
                && strcasecmp($user->email, (string) $socialiteUser->getEmail()) === 0
                && $this->hasAuthoritativeEmail($ssoProvider, $socialiteUser)) {
                // Whoever signed up with this address before the owner proved it must not keep access.
                $user->forceFill([
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(40)),
                ])->save();
                $user->tokens()->delete();
            }

            $linking->linkVerifiedEmail($user);
            $this->syncAttendeeNameToUser($user->load('attendee'));

            if ($user->email_verified_at === null && $wasNewUser) {
                event(new Registered($user));
            }

            $action = $wasNewUser ? 'user.sso_registered' : 'user.sso_login';
            AuditLog::record($action, $user, ['provider' => $ssoProvider->value]);

            return response()->json([
                'token' => $user->createToken('api')->plainTextToken,
                'user' => UserResource::make($user),
                'is_new_user' => $wasNewUser,
            ], $wasNewUser ? 201 : 200);
        });
    }

    private function hasAuthoritativeEmail(SsoProvider $provider, mixed $socialiteUser): bool
    {
        if ($provider !== SsoProvider::Google) {
            return false;
        }

        $claims = $socialiteUser->getRaw();
        $email = strtolower((string) $socialiteUser->getEmail());

        return (bool) ($claims['verified_email'] ?? false)
            && (str_ends_with($email, '@gmail.com') || filled($claims['hd'] ?? null));
    }

    private function syncAttendeeNameToUser(User $user): void
    {
        $attendee = $user->attendee;
        if ($attendee === null) {
            return;
        }

        $user->syncNameFrom($attendee);
    }

    private function claimWithSso(string $claimToken, $socialiteUser, SsoProvider $provider, AttendeeQrClaimService $claims, AttendeeLinkingService $linking): JsonResponse
    {
        $claim = $claims->claim($claimToken);
        if ($claim === null) {
            throw ValidationException::withMessages(['claim_token' => 'Your verification session has expired. Scan your ID card again.']);
        }

        if (! $claims->allowClaimCompletion($claimToken)) {
            throw ValidationException::withMessages(['claim_token' => 'Too many attempts. Scan your ID card again.']);
        }

        $result = DB::transaction(function () use ($claim, $socialiteUser, $provider, $linking) {
            $attendee = Attendee::withoutGlobalScopes()->lockForUpdate()->find($claim['attendee_id']);
            if ($attendee === null || $attendee->user_id !== null) {
                throw ValidationException::withMessages(['claim_token' => 'This attendee already has an account.']);
            }

            if (filled($attendee->email_address)) {
                throw ValidationException::withMessages(['claim_token' => 'This attendee already has an email address.']);
            }

            $email = strtolower($socialiteUser->getEmail());
            if (! ($claim['email_verified'] ?? false) || strtolower($claim['email'] ?? '') !== $email) {
                throw ValidationException::withMessages(['email' => 'Verify this email before continuing with SSO.']);
            }

            if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'This email address already has an account.']);
            }

            if ($linking->emailTakenInEvent($email, $attendee)) {
                throw ValidationException::withMessages(['email' => 'This email address is already registered for this event. Sign in with it and verify it instead.']);
            }

            if (UserIdentity::query()->where('provider', $provider->value)->where('provider_id', $socialiteUser->getId())->exists()) {
                throw ValidationException::withMessages(['email' => 'This SSO account is already linked to another user.']);
            }

            $user = User::create([
                'name' => trim(collect([$attendee->first_name, $attendee->middle_name, $attendee->last_name])->filter()->implode(' ')),
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
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
            $linking->linkVerifiedEmail($user);

            UserIdentity::create([
                'user_id' => $user->id,
                'provider' => $provider->value,
                'provider_id' => $socialiteUser->getId(),
                'email' => $email,
            ]);

            AuditLog::record('attendee.qr_claimed_sso', $attendee, ['user_id' => $user->id, 'provider' => $provider->value]);

            return [
                'token' => $user->createToken('api')->plainTextToken,
                'user' => UserResource::make($user->load('attendee')),
                'is_new_user' => true,
            ];
        });

        $claims->forgetClaim($claimToken);

        return response()->json($result, 201);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        Password::sendResetLink(['email' => $email]);

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ], 200);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($validated) {
            DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            $status = Password::broker()->reset(
                [
                    'email' => $validated['email'],
                    'token' => $validated['token'],
                    'password' => $validated['password'],
                ],
                function (User $user, string $password): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();
                    $user->tokens()->delete();
                    AuditLog::record('user.password_reset', $user);
                }
            );

            if ($status !== Password::PASSWORD_RESET) {
                throw ValidationException::withMessages(['email' => [trans($status)]]);
            }

            return response()->json(['message' => 'Your password has been successfully reset.']);
        });
    }

    public function verifyEmail(Request $request, string $id, string $hash, AttendeeLinkingService $linking): JsonResponse
    {
        $user = User::withoutGlobalScopes()->findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => 'Invalid verification link.'], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        DB::transaction(function () use ($user, $linking): void {
            $user->markEmailAsVerified();
            $linking->linkVerifiedEmail($user);
        });

        event(new Verified($user));

        return response()->json(['message' => 'Email verified successfully.'], 200);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent to your email.'], 200);
    }

    public function showInvite(string $token): JsonResponse
    {
        $user = User::withoutGlobalScopes()
            ->where('invite_token', $token)
            ->where('invited_at', '>=', now()->subDays(config('auth.invite_expire')))
            ->first();

        abort_if($user === null, 404, 'Invalid invite token.');

        return response()->json([
            'email' => $user->email,
            'role' => $user->role,
            'organization' => $user->organization?->name,
        ]);
    }

    public function acceptInvite(AcceptUserInviteRequest $request, AttendeeLinkingService $linking): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $linking) {
            $user = User::withoutGlobalScopes()
                ->where('invite_token', $data['token'])
                ->where('email', $data['email'])
                ->where('invited_at', '>=', now()->subDays(config('auth.invite_expire')))
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                throw ValidationException::withMessages(['token' => ['Invalid or expired invite.']]);
            }

            $name = trim(collect([$data['first_name'], $data['middle_name'] ?? null, $data['last_name']])
                ->filter()
                ->implode(' '));

            $user->update([
                'name' => $name,
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'password' => Hash::make($data['password']),
                'email_verified_at' => now(),
                'invite_token' => null,
            ]);

            AuditLog::record('user.invite_accepted', $user);
            $linking->linkVerifiedEmail($user);

            return response()->json([
                'token' => $user->createToken('api')->plainTextToken,
                'user' => UserResource::make($user),
            ], 201);
        });
    }
}
