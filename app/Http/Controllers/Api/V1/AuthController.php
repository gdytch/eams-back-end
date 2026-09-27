<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
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
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Models\UserIdentity;
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

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data, $request) {
            // Resolve the invited attendee if an attendee invite token is provided
            $attendee = null;
            $event = null;
            $attendeeMatchMethod = null; // 'personal_token' or 'email_merge'

            if ($request->filled('invite_token')) {
                // First check if it's an attendee invite token
                $attendee = Attendee::withoutGlobalScopes()
                    ->where('invite_token', $data['invite_token'])
                    ->where('invited_at', '>=', now()->subDays(config('auth.invite_expire')))
                    ->whereNull('user_id')
                    ->lockForUpdate()
                    ->first();

                // If not found, check if it's an event invite token
                if ($attendee === null) {
                    $event = Event::withoutGlobalScopes()
                        ->where('invite_token', $data['invite_token'])
                        ->first();

                    // If event token, try to find unclaimed attendee by email in same org
                    if ($event !== null) {
                        $attendee = Attendee::withoutGlobalScopes()
                            ->where('email_address', $data['email'])
                            ->whereNull('user_id')
                            ->lockForUpdate()
                            ->first();

                        if ($attendee !== null && $attendee->organization_id !== null && $attendee->organization_id !== $event->organization_id) {
                            // Different org, don't merge
                            $attendee = null;
                        } elseif ($attendee !== null) {
                            $attendeeMatchMethod = 'email_merge';
                        }
                    }
                } else {
                    $attendeeMatchMethod = 'personal_token';
                }

                if ($attendee === null && $event === null) {
                    throw ValidationException::withMessages(['invite_token' => 'Invalid or expired invite.']);
                }
            }

            $name = trim(collect([$data['first_name'], $data['middle_name'] ?? null, $data['last_name']])
                ->filter()
                ->implode(' '));

            $organizationId = $attendee?->organization_id ?? $data['organization_id'];

            // Mark email verified only if attendee email matches by personal token
            $emailVerifiedAt = null;
            if ($attendeeMatchMethod === 'personal_token' && $attendee && $attendee->email_address === $data['email']) {
                $emailVerifiedAt = now();
            }

            $user = User::create([
                'name' => $name,
                'email' => $data['email'],
                'password' => $data['password'],
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'role' => UserRole::Attendee,
                'organization_id' => $organizationId,
                'email_verified_at' => $emailVerifiedAt,
            ]);

            // Link the user to the attendee and clear the invite token, or create a new attendee for self-registered users
            if ($attendee) {
                $attendeeData = [
                    'user_id' => $user->id,
                    'invite_token' => null,
                ];
                if ($data['organization_id'] === $attendee->organization_id) {
                    $attendeeData += [
                        'organization_level' => $data['organization_level'],
                        'union_id' => $data['union_id'],
                        'mission_id' => $data['mission_id'] ?? null,
                    ];
                }
                $attendee->update($attendeeData);

                if ($attendeeMatchMethod === 'personal_token') {
                    AuditLog::record('attendee.invite_claimed', $attendee, ['user_id' => $user->id]);
                } else {
                    AuditLog::record('attendee.email_merged', $attendee, ['user_id' => $user->id]);
                }
            } else {
                // Self-registration: create a new attendee linked to this user
                $attendee = Attendee::create([
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                    'organization_level' => $data['organization_level'],
                    'union_id' => $data['union_id'],
                    'mission_id' => $data['mission_id'] ?? null,
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => $data['last_name'],
                    'email_address' => $data['email'],
                ]);

                AuditLog::record('attendee.self_registered', $attendee, ['user_id' => $user->id]);
            }

            // Only fire Registered event if email is not yet verified (needs verification flow)
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

    public function sso(SsoLoginRequest $request, string $provider, AttendeeQrClaimService $claims): JsonResponse
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
            return $this->claimWithSso($request->validated('claim_token'), $socialiteUser, $ssoProvider, $claims);
        }

        return DB::transaction(function () use ($data, $socialiteUser, $ssoProvider, $request) {
            $providerId = $socialiteUser->getId();

            // Try to find existing identity
            $identity = UserIdentity::where('provider', $ssoProvider->value)
                ->where('provider_id', $providerId)
                ->first();

            if ($identity !== null) {
                $user = $identity->user;
                $wasNewUser = false;
            } else {
                // Try to match by email (for account linking)
                $user = User::withoutGlobalScopes()
                    ->where('email', $socialiteUser->getEmail())
                    ->first();

                if ($user !== null && ! $this->hasAuthoritativeEmail($ssoProvider, $socialiteUser)) {
                    throw ValidationException::withMessages([
                        'email' => 'This provider has not verified your email. Sign in with your existing account or reset its password.',
                    ]);
                }

                if ($user === null) {
                    // Check if email exists in attendee table
                    $attendee = Attendee::withoutGlobalScopes()
                        ->where('email_address', $socialiteUser->getEmail())
                        ->whereNull('user_id')
                        ->lockForUpdate()
                        ->first();

                    // Check invite token (can be event or attendee token)
                    $inviteEvent = null;
                    $inviteAttendee = null;

                    if ($request->filled('invite_token')) {
                        // First check if it's an event invite token
                        $inviteEvent = Event::withoutGlobalScopes()
                            ->where('invite_token', $request->validated('invite_token'))
                            ->where('status', EventStatus::Published)
                            ->first();

                        // If not found, check if it's an attendee invite token
                        if ($inviteEvent === null) {
                            $inviteAttendee = Attendee::withoutGlobalScopes()
                                ->where('invite_token', $request->validated('invite_token'))
                                ->where('invited_at', '>=', now()->subDays(config('auth.invite_expire')))
                                ->whereNull('user_id')
                                ->lockForUpdate()
                                ->first();
                        }
                    }

                    if ($attendee !== null && ! $this->hasAuthoritativeEmail($ssoProvider, $socialiteUser) && $inviteEvent === null && $inviteAttendee === null) {
                        throw ValidationException::withMessages([
                            'email' => 'This provider has not verified your email. Use your invitation link or sign in with your existing account.',
                        ]);
                    }

                    $hasRegistrationTerritory = $request->filled('organization_id')
                        && $request->filled('organization_level')
                        && $request->filled('union_id');

                    // Validate: either attendee exists, a valid invite was provided, or the registration dialog supplied a territory
                    if ($attendee === null && $inviteEvent === null && $inviteAttendee === null && ! $hasRegistrationTerritory) {
                        throw ValidationException::withMessages([
                            'email' => 'This email is not registered. Please contact your administrator.',
                        ]);
                    }

                    // Resolve organization: prioritize attendee, then invite (event or attendee), then self-registration territory
                    $organizationId = $attendee?->organization_id
                        ?? $inviteAttendee?->organization_id
                        ?? $inviteEvent?->organization_id
                        ?? $data['organization_id'];

                    // An existing attendee record is the authoritative source for the user's name.
                    $attendeeToLink = $attendee ?? $inviteAttendee;
                    $name = $attendeeToLink !== null
                        ? trim(collect([$attendeeToLink->first_name, $attendeeToLink->middle_name, $attendeeToLink->last_name])->filter()->implode(' '))
                        : ($socialiteUser->getName() ?? Str::before($socialiteUser->getEmail(), '@'));
                    $firstName = $attendeeToLink?->first_name ?? (Str::before($name, ' ') ?: Str::before($socialiteUser->getEmail(), '@'));
                    $middleName = $attendeeToLink?->middle_name;
                    $lastName = $attendeeToLink?->last_name ?? (Str::contains($name, ' ') ? Str::after($name, ' ') : '');

                    $user = User::create([
                        'name' => $name,
                        'email' => $socialiteUser->getEmail(),
                        'password' => Hash::make(Str::random(40)),
                        'first_name' => $firstName,
                        'middle_name' => $middleName,
                        'last_name' => $lastName,
                        'role' => UserRole::Attendee,
                        'organization_id' => $organizationId,
                        'email_verified_at' => $this->hasAuthoritativeEmail($ssoProvider, $socialiteUser) ? now() : null,
                    ]);

                    // Link the user to the attendee if found (either from pre-existing attendee or invite attendee)
                    if ($attendeeToLink !== null) {
                        $attendeeToLink->update([
                            'user_id' => $user->id,
                            'invite_token' => null,
                        ]);
                        if ($request->validated('organization_id') === $attendeeToLink->organization_id && $request->filled('organization_level')) {
                            $attendeeToLink->update([
                                'organization_level' => $request->validated('organization_level'),
                            ]);
                        }
                        if ($request->validated('organization_id') === $attendeeToLink->organization_id && $request->filled('union_id')) {
                            $attendeeToLink->update([
                                'union_id' => $request->validated('union_id'),
                            ]);
                        }
                        if ($request->validated('organization_id') === $attendeeToLink->organization_id && $request->filled('mission_id')) {
                            $attendeeToLink->update([
                                'mission_id' => $request->validated('mission_id'),
                            ]);
                        }

                        AuditLog::record('attendee.sso_merged', $attendeeToLink, ['user_id' => $user->id]);
                    } else {
                        // Self-registration: create a new attendee linked to this user
                        $name = $socialiteUser->getName() ?? Str::before($socialiteUser->getEmail(), '@');
                        $firstName = Str::before($name, ' ') ?: Str::before($socialiteUser->getEmail(), '@');
                        $lastName = Str::contains($name, ' ') ? Str::after($name, ' ') : '';
                        $attendee = Attendee::create([
                            'user_id' => $user->id,
                            'organization_id' => $organizationId,
                            'organization_level' => $data['organization_level'] ?? null,
                            'union_id' => $data['union_id'] ?? null,
                            'mission_id' => $data['mission_id'] ?? null,
                            'first_name' => $firstName,
                            'middle_name' => $data['middle_name'] ?? null,
                            'last_name' => $lastName,
                            'email_address' => $socialiteUser->getEmail(),
                        ]);

                        AuditLog::record('attendee.self_registered', $attendee, ['user_id' => $user->id]);
                    }

                    $wasNewUser = true;
                } else {
                    $wasNewUser = false;

                    // Check if existing user should be merged with an attendee
                    $attendee = Attendee::withoutGlobalScopes()
                        ->where('email_address', $user->email)
                        ->whereNull('user_id')
                        ->lockForUpdate()
                        ->first();

                    if ($attendee !== null) {
                        // Link the attendee to the user if organization_id matches or user doesn't have one
                        if ($user->organization_id === null || $user->organization_id === $attendee->organization_id) {
                            $user->update([
                                'name' => trim(collect([$attendee->first_name, $attendee->middle_name, $attendee->last_name])->filter()->implode(' ')),
                                'first_name' => $attendee->first_name,
                                'middle_name' => $attendee->middle_name,
                                'last_name' => $attendee->last_name,
                            ]);
                            $attendee->update([
                                'user_id' => $user->id,
                                'invite_token' => null,
                            ]);

                            if ($request->validated('organization_id') === $attendee->organization_id && $request->filled('organization_level')) {
                                $attendee->update([
                                    'organization_level' => $request->validated('organization_level'),
                                ]);
                            }
                            if ($request->validated('organization_id') === $attendee->organization_id && $request->filled('union_id')) {
                                $attendee->update([
                                    'union_id' => $request->validated('union_id'),
                                ]);
                            }
                            if ($request->validated('organization_id') === $attendee->organization_id && $request->filled('mission_id')) {
                                $attendee->update([
                                    'mission_id' => $request->validated('mission_id'),
                                ]);
                            }

                            // Update user's organization_id if currently null
                            if ($user->organization_id === null) {
                                $user->update(['organization_id' => $attendee->organization_id]);
                            }

                            AuditLog::record('attendee.sso_merged', $attendee, ['user_id' => $user->id]);
                        }
                    } elseif (
                        $request->filled('organization_id')
                        && $request->filled('organization_level')
                        && $request->filled('union_id')
                    ) {
                        // Self-registration: create a new attendee linked to this user
                        $name = $socialiteUser->getName() ?? Str::before($socialiteUser->getEmail(), '@');
                        $firstName = Str::before($name, ' ') ?: Str::before($socialiteUser->getEmail(), '@');
                        $lastName = Str::contains($name, ' ') ? Str::after($name, ' ') : '';
                        $attendee = Attendee::create([
                            'user_id' => $user->id,
                            'organization_id' => $data['organization_id'],
                            'organization_level' => $data['organization_level'],
                            'union_id' => $data['union_id'],
                            'mission_id' => $data['mission_id'] ?? null,
                            'first_name' => $firstName,
                            'middle_name' => $data['middle_name'] ?? null,
                            'last_name' => $lastName,
                            'email_address' => $socialiteUser->getEmail(),
                        ]);

                        AuditLog::record('attendee.self_registered', $attendee, ['user_id' => $user->id]);
                    }
                }

                // Create the identity link
                UserIdentity::create([
                    'user_id' => $user->id,
                    'provider' => $ssoProvider->value,
                    'provider_id' => $providerId,
                    'email' => $socialiteUser->getEmail(),
                ]);
            }

            $this->syncAttendeeNameToUser($user);

            // Set organization_id from invite_token if currently null
            if ($user->organization_id === null && $request->filled('invite_token')) {
                $event = Event::withoutGlobalScopes()
                    ->where('invite_token', $request->validated('invite_token'))
                    ->where('status', EventStatus::Published)
                    ->first();

                if ($event !== null) {
                    $user->update(['organization_id' => $event->organization_id]);
                }
            }

            // Mark email as verified if not already
            if ($user->email_verified_at === null
                && strcasecmp($user->email, (string) $socialiteUser->getEmail()) === 0
                && $this->hasAuthoritativeEmail($ssoProvider, $socialiteUser)) {
                $user->update(['email_verified_at' => now()]);
            }

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

        $user->update([
            'name' => trim(collect([$attendee->first_name, $attendee->middle_name, $attendee->last_name])->filter()->implode(' ')),
            'first_name' => $attendee->first_name,
            'middle_name' => $attendee->middle_name,
            'last_name' => $attendee->last_name,
        ]);
    }

    private function claimWithSso(string $claimToken, $socialiteUser, SsoProvider $provider, AttendeeQrClaimService $claims): JsonResponse
    {
        $claim = $claims->claim($claimToken);
        if ($claim === null) {
            throw ValidationException::withMessages(['claim_token' => 'Your verification session has expired. Scan your ID card again.']);
        }

        if (! $claims->allowClaimCompletion($claimToken)) {
            throw ValidationException::withMessages(['claim_token' => 'Too many attempts. Scan your ID card again.']);
        }

        $result = DB::transaction(function () use ($claim, $socialiteUser, $provider) {
            $registration = EventRegistration::query()->lockForUpdate()->find($claim['registration_id']);
            if ($registration === null) {
                throw ValidationException::withMessages(['claim_token' => 'Your verification session is no longer available.']);
            }

            $attendee = Attendee::withoutGlobalScopes()->lockForUpdate()->find($registration->attendee_id);
            if ($attendee === null || $attendee->user_id !== null) {
                throw ValidationException::withMessages(['claim_token' => 'This attendee already has an account.']);
            }

            $email = strtolower($socialiteUser->getEmail());
            if (filled($attendee->email_address) && strtolower($attendee->email_address) !== $email) {
                throw ValidationException::withMessages(['email' => 'Use the email address saved with your attendee record. You can ask the event coordinator for assistance.']);
            }

            if (blank($attendee->email_address) && (! ($claim['email_verified'] ?? false) || strtolower($claim['email'] ?? '') !== $email)) {
                throw ValidationException::withMessages(['email' => 'Verify this email before continuing with SSO.']);
            }

            if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'This email address already has an account.']);
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
                'organization_id' => $attendee->organization_id,
                'email_verified_at' => now(),
            ]);

            $attendee->update([
                'user_id' => $user->id,
                'email_address' => $email,
                'invite_token' => null,
            ]);

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

    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::withoutGlobalScopes()->findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['message' => 'Invalid verification link.'], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.'], 200);
        }

        $user->markEmailAsVerified();

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

    public function acceptInvite(AcceptUserInviteRequest $request): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data) {
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

            return response()->json([
                'token' => $user->createToken('api')->plainTextToken,
                'user' => UserResource::make($user),
            ], 201);
        });
    }
}
