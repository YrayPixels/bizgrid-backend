<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminRoleMiddleware
{
    /** @param string $roles Comma-separated roles e.g. super_admin,billing */
    public function handle(Request $request, Closure $next, string $roles = 'super_admin'): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_admin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $allowed = array_map('trim', explode(',', $roles));
        $role = $user->admin_role ?? 'super_admin';

        if (! in_array($role, $allowed, true)) {
            return response()->json(['message' => 'Insufficient admin permissions.'], 403);
        }

        return $next($request);
    }
}
