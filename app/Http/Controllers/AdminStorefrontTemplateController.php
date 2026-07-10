<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Models\StorefrontTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminStorefrontTemplateController extends Controller
{
    use InvalidatesApiCache;

    public function index(): JsonResponse
    {
        StorefrontTemplate::ensureSeeded();

        $templates = StorefrontTemplate::query()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (StorefrontTemplate $template) => $template->toCatalogArray())
            ->values();

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'label' => 'sometimes|required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'best_for' => 'nullable|string|max:160',
            'preview' => 'sometimes|required|string|max:60',
            'is_active' => 'sometimes|required|boolean',
            'sort_order' => 'sometimes|required|integer|min:0|max:65535',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $template = StorefrontTemplate::find($id);
        if (! $template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        $template->fill($validator->validated())->save();

        $this->invalidateTemplateApiCache();

        return response()->json([
            'success' => true,
            'message' => 'Template updated',
            'data' => $template->fresh()->toCatalogArray(),
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'is_active' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $template = StorefrontTemplate::find($id);
        if (! $template) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found',
            ], 404);
        }

        $template->is_active = (bool) $validator->validated()['is_active'];
        $template->save();

        $this->invalidateTemplateApiCache();

        return response()->json([
            'success' => true,
            'message' => 'Template status updated',
            'data' => $template->fresh()->toCatalogArray(),
        ]);
    }
}
