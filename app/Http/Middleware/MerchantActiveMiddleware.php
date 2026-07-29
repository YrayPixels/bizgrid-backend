<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\MerchantMembershipService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantActiveMiddleware
{
    public function __construct(private MerchantMembershipService $membership) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $membership = $this->membership->membershipFor($user);
        if ($membership && $membership['merchant']->status === 'suspended') {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact support.',
                'reason' => $membership['merchant']->suspension_reason,
            ], 403);
        }

        return $next($request);
    }
}
