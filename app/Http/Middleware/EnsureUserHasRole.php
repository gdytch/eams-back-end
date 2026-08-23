<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null, 401);

        $allowedRoles = array_map(fn (string $role) => UserRole::from($role), $roles);

        abort_unless(in_array($user->role, $allowedRoles, strict: true), 403, 'You do not have permission to perform this action.');

        return $next($request);
    }
}
