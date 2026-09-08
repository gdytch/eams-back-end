<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Enums\SsoProvider;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\SsoLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        $name = trim(collect([$data['first_name'], $data['middle_name'] ?? null, $data['last_name']])
            ->filter()
            ->implode(' '));

        $user = User::create([
            'name' => $name,
            'email' => $data['email'],
            'password' => $data['password'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'role' => UserRole::Attendee,
            'organization_id' => null,
        ]);

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => UserResource::make($user),
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserResource::make($request->user()),
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
                    // Resolve organization from invite_token if provided
                    $organizationId = null;
                    if ($request->filled('invite_token')) {
                        $event = Event::withoutGlobalScopes()
                            ->where('invite_token', $request->validated('invite_token'))
                            ->where('status', EventStatus::Published)
                            ->first();

                        if ($event !== null) {
                            $organizationId = $event->organization_id;
                        }
                    }

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

                    $wasNewUser = true;
                } else {
                    $wasNewUser = false;
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
}
