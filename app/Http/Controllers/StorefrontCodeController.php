<?php

namespace App\Http\Controllers;

use App\Agents\StorefrontCodeAgent;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontCodeController extends Controller
{
    public function generate(Request $request, StorefrontCodeAgent $agent): JsonResponse
    {
        $data = $request->validate([
            'store_id' => 'required|integer|exists:stores,id',
            'style_note' => 'nullable|string|max:500',
            'products' => 'nullable|array',
            'products.*.name' => 'required|string',
            'products.*.price' => 'required|numeric',
            'products.*.description' => 'nullable|string',
            'products.*.currency' => 'nullable|string',
            'products.*.image_url' => 'nullable|string',
        ]);

        $store = Store::with('merchant')->findOrFail($data['store_id']);

        // Verify the store belongs to the authenticated user
        if ($store->merchant_id !== $request->user()?->merchant?->id) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $result = $agent->generate($store, [
            'style_note' => $data['style_note'] ?? null,
            'products' => $data['products'] ?? [],
        ]);

        if (! $result) {
            return response()->json(['error' => 'Code generation failed.'], 500);
        }

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        // Store the generated code on the store (use description field as temporary storage)
        // TODO: add storefront_code column via migration
        $store->description = $store->description; // keep existing

        return response()->json([
            'html' => $result['html'],
            'store_id' => $store->id,
        ]);
    }
}
