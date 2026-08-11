<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AdminPermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminPermissionMiddleware
{
    /** @param string $permissions Comma-separated permission keys e.g. view_as_merchant */
    public function handle(Request $request, Closure $next, string $permissions = ''): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_admin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $required = array_values(array_filter(array_map('trim', explode(',', $permissions))));
        if ($required === []) {
            return $next($request);
        }

        foreach ($required as $permission) {
            if (AdminPermissions::userHas($user, $permission)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Insufficient admin permissions.'], 403);
    }
}
