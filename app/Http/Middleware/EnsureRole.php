<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role check: `role:manager,administrator` admits only those roles.
 * Every signed-in /api/v1 route must declare one (deny by default).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $role) => Role::from($role), $roles);

        if (! $request->user()?->hasRole(...$allowed)) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
