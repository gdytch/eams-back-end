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
use App\Models\User;
use App\Models\UserIdentity;
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
                    ->whereNull('user_id')
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
            }

            $name = trim(collect([$data['first_name'], $data['middle_name'] ?? null, $data['last_name']])
                ->filter()
                ->implode(' '));

            $organizationId = $data['organization_id'];

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
                $attendee->update([
                    'user_id' => $user->id,
                    'invite_token' => null,
                    'organization_id' => $organizationId,
                    'organization_level' => $data['organization_level'],
                    'union_id' => $data['union_id'],
                    'mission_id' => $data['mission_id'] ?? null,
                ]);

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

    public function sso(SsoLoginRequest $request, string $provider): JsonResponse
    {
        $ssoProvider = SsoProvider::tryFrom($provider);
        abort_if($ssoProvider === null, 404);

        try {
            $socialiteUser = Socialite::driver($ssoProvider->value)->stateless()->userFromToken($request->validated('token'));
        } catch (Throwable) {
            return response()->json(['message' => 'Unable to verify SSO token.'], 401);
        }

        abort_if(blank($socialiteUser->getEmail()), 422, 'Email permission is required to sign in.');

        return DB::transaction(function () use ($socialiteUser, $ssoProvider, $request) {
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

                if ($user === null) {
                    // Check if email exists in attendee table
                    $attendee = Attendee::withoutGlobalScopes()
                        ->where('email_address', $socialiteUser->getEmail())
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
                                ->whereNull('user_id')
                                ->first();
                        }
                    }

                    // Validate: either attendee exists or valid invite token provided
                    if ($attendee === null && $inviteEvent === null && $inviteAttendee === null) {
                        throw ValidationException::withMessages([
                            'email' => 'This email is not registered. Please contact your administrator.',
                        ]);
                    }

                    // Resolve organization: prioritize attendee, then invite (event or attendee)
                    $organizationId = $attendee?->organization_id ?? $inviteAttendee?->organization_id ?? $inviteEvent?->organization_id;

                    // Create new user
                    $name = $socialiteUser->getName() ?? Str::before($socialiteUser->getEmail(), '@');
                    $firstName = Str::before($name, ' ') ?: Str::before($socialiteUser->getEmail(), '@');
                    $lastName = Str::contains($name, ' ') ? Str::after($name, ' ') : '';

                    $user = User::create([
                        'name' => $name,
                        'email' => $socialiteUser->getEmail(),
                        'password' => Hash::make(Str::random(40)),
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'role' => UserRole::Attendee,
                        'organization_id' => $organizationId,
                        'email_verified_at' => now(),
                    ]);

                    // Link the user to the attendee if found (either from pre-existing attendee or invite attendee)
                    $attendeeToLink = $attendee ?? $inviteAttendee;
                    if ($attendeeToLink !== null) {
                        $attendeeToLink->update([
                            'user_id' => $user->id,
                            'invite_token' => null,
                        ]);
                        if ($request->filled('organiztion_id')) {
                            $attendeeToLink->update([
                                'organization_id' => $request->validated('organization_id'),
                            ]);
                        }
                        if ($request->filled('organization_level')) {
                            $attendeeToLink->update([
                                'organization_level' => $request->validated('organization_level'),
                            ]);
                        }
                        if ($request->filled('union_id')) {
                            $attendeeToLink->update([
                                'union_id' => $request->validated('union_id'),
                            ]);
                        }
                        if ($request->filled('mission_id')) {
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
                            'organization_id' => $request->validated('organization_id'),
                            'organization_level' => $request->validated('organization_level'),
                            'union_id' => $request->validated('union_id'),
                            'mission_id' => $request->validated('mission_id') ?? null,
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
                        ->first();

                    if ($attendee !== null) {
                        // Link the attendee to the user if organization_id matches or user doesn't have one
                        if ($user->organization_id === null || $user->organization_id === $attendee->organization_id) {
                            $attendee->update([
                                'user_id' => $user->id,
                                'invite_token' => null,
                            ]);

                            if ($request->filled('organiztion_id')) {
                                $attendee->update([
                                    'organization_id' => $request->validated('organization_id'),
                                ]);
                            }
                            if ($request->filled('organization_level')) {
                                $attendee->update([
                                    'organization_level' => $request->validated('organization_level'),
                                ]);
                            }
                            if ($request->filled('union_id')) {
                                $attendee->update([
                                    'union_id' => $request->validated('union_id'),
                                ]);
                            }
                            if ($request->filled('mission_id')) {
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
                    } else {
                        // Self-registration: create a new attendee linked to this user
                        $name = $socialiteUser->getName() ?? Str::before($socialiteUser->getEmail(), '@');
                        $firstName = Str::before($name, ' ') ?: Str::before($socialiteUser->getEmail(), '@');
                        $lastName = Str::contains($name, ' ') ? Str::after($name, ' ') : '';
                        $attendee = Attendee::create([
                            'user_id' => $user->id,
                            'organization_id' => $request->validated('organization_id'),
                            'organization_level' => $request->validated('organization_level'),
                            'union_id' => $request->validated('union_id'),
                            'mission_id' => $request->validated('mission_id') ?? null,
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
            if ($user->email_verified_at === null) {
                $user->update(['email_verified_at' => now()]);
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

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        // Check if email exists in either user or attendee table
        $emailExists = User::where('email', $email)->exists() ||
            Attendee::where('email_address', $email)->exists();

        if (! $emailExists) {
            throw ValidationException::withMessages([
                'email' => 'No account found with that email address.',
            ]);
        }

        // Send reset link
        Password::sendResetLink(['email' => $email]);

        return response()->json([
            'message' => 'A password reset link has been sent to your email.',
        ], 200);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($validated) {
            // Manually verify the password reset token from the database
            $resetRecord = DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->first();

            if (! $resetRecord || ! Hash::check($validated['token'], $resetRecord->token)) {
                throw ValidationException::withMessages([
                    'email' => [trans('passwords.token')],
                ]);
            }

            // Check if token has expired (default: 60 minutes)
            if (now()->diffInMinutes($resetRecord->created_at) > 60) {
                DB::table('password_reset_tokens')
                    ->where('email', $validated['email'])
                    ->delete();

                throw ValidationException::withMessages([
                    'email' => [trans('passwords.token')],
                ]);
            }

            // Find the user without global scopes
            $user = User::withoutGlobalScopes()->where('email', $validated['email'])->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'email' => [trans('auth.failed')],
                ]);
            }

            // Update password and token
            $user->update([
                'password' => $validated['password'],
                'remember_token' => Str::random(60),
            ]);

            // Revoke all existing Sanctum tokens
            $user->tokens()->delete();

            // Delete the reset token
            DB::table('password_reset_tokens')
                ->where('email', $validated['email'])
                ->delete();

            AuditLog::record('user.password_reset', $user);

            return response()->json([
                'message' => 'Your password has been successfully reset.',
            ], 200);
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
                ->first();

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
