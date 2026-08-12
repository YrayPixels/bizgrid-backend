<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StorehauseHelpers;
use App\Services\ShopperDemandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopperDemandController extends Controller
{
    use StorehauseHelpers;

    public function __construct(
        private readonly ShopperDemandService $demand,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $store = $this->findOwnedStoreForUser($request);
        $days = (int) $request->query('days', 30);
        $markSeen = $request->boolean('mark_seen', false);

        return response()->json(
            $this->demand->summary($store, $days, $markSeen),
        );
    }
}
