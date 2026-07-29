<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\MerchantMembershipService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantCapabilityMiddleware
{
    public function __construct(private MerchantMembershipService $membership) {}

    /**
     * @param  string  ...$capabilities  One of: sell, admin, manage_staff
     */
    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $allowed = false;
        foreach ($capabilities as $capability) {
            $allowed = match ($capability) {
                'sell' => $this->membership->canSell($user),
                'admin' => $this->membership->canAccessAdmin($user),
                'manage_staff' => $this->membership->canManageStaff($user),
                default => false,
            };
            if ($allowed) {
                break;
            }
        }

        if (! $allowed) {
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'code' => 'forbidden_role',
            ], 403);
        }

        return $next($request);
    }
}
