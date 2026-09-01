<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BizfestPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BizfestPartnerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $programme = $request->get('programme', 'bizfest-1');

        $partners = BizfestPartner::query()
            ->where('programme', $programme)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (BizfestPartner $partner) => $this->formatPublic($partner));

        return response()->json([
            'success' => true,
            'data' => $partners,
        ]);
    }

    private function formatPublic(BizfestPartner $partner): array
    {
        return [
            'id' => $partner->id,
            'name' => $partner->name,
            'label' => $partner->label,
            'logo_url' => $partner->logo_url,
            'website_url' => $partner->website_url,
        ];
    }
}
