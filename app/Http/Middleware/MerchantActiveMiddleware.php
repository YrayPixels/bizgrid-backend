<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantActiveMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $merchant = Merchant::where('owner_user_id', $user->id)->first();

        if ($merchant && $merchant->status === 'suspended') {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact support.',
                'reason' => $merchant->suspension_reason,
            ], 403);
        }

        return $next($request);
    }
}
