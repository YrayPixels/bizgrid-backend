<?php

namespace App\Http\Controllers;

use App\Models\StorefrontTemplate;
use Illuminate\Http\JsonResponse;

class StorefrontTemplateController extends Controller
{
    public function active(): JsonResponse
    {
        $templates = StorefrontTemplate::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (StorefrontTemplate $template) => $template->toCatalogArray())
            ->values();

        return response()->json([
            'templates' => $templates,
        ]);
    }
}
