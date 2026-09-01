<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Models\BizfestPartner;
use App\Services\AdminAuditService;
use App\Services\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminBizfestPartnerController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly AdminAuditService $audit,
        private readonly MediaStorageService $media,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $programme = $request->get('programme', 'bizfest-1');

        $partners = BizfestPartner::query()
            ->where('programme', $programme)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (BizfestPartner $partner) => $this->format($partner));

        return response()->json([
            'success' => true,
            'data' => $partners,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'programme' => 'nullable|string|max:40',
            'name' => 'required|string|max:200',
            'label' => 'nullable|string|max:120',
            'logo_url' => 'nullable|url|max:500',
            'website_url' => 'nullable|url|max:500',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active' => 'nullable|boolean',
        ]);

        $partner = BizfestPartner::create([
            'programme' => $data['programme'] ?? 'bizfest-1',
            'name' => $data['name'],
            'label' => $data['label'] ?? null,
            'logo_url' => $data['logo_url'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->audit->log($request, 'bizfest.partner_created', 'bizfest_partner', $partner->id, [
            'name' => $partner->name,
        ]);
        $this->invalidateAdminApiCache();

        return response()->json([
            'success' => true,
            'message' => 'Partner created',
            'data' => $this->format($partner),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $partner = BizfestPartner::query()->find($id);
        if (! $partner) {
            return response()->json(['success' => false, 'message' => 'Partner not found'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:200',
            'label' => 'nullable|string|max:120',
            'logo_url' => 'nullable|url|max:500',
            'website_url' => 'nullable|url|max:500',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_active' => 'nullable|boolean',
        ]);

        $partner->fill($data);
        $partner->save();

        $this->audit->log($request, 'bizfest.partner_updated', 'bizfest_partner', $partner->id, [
            'name' => $partner->name,
        ]);
        $this->invalidateAdminApiCache();

        return response()->json([
            'success' => true,
            'message' => 'Partner updated',
            'data' => $this->format($partner),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $partner = BizfestPartner::query()->find($id);
        if (! $partner) {
            return response()->json(['success' => false, 'message' => 'Partner not found'], 404);
        }

        $name = $partner->name;
        $partner->delete();

        $this->audit->log($request, 'bizfest.partner_deleted', 'bizfest_partner', $id, [
            'name' => $name,
        ]);
        $this->invalidateAdminApiCache();

        return response()->json([
            'success' => true,
            'message' => 'Partner deleted',
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'logo' => [
                'required',
                'file',
                'max:5120',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp',
            ],
        ]);

        $file = $data['logo'];
        $mime = $file->getMimeType();
        $extensionMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $extension = $extensionMap[$mime] ?? 'bin';
        $filename = Str::uuid().'.'.$extension;
        $url = $this->media->storeUpload('storehause/uploads/bizfest/partners', $file, $filename);

        $this->audit->log($request, 'bizfest.partner_logo_uploaded', 'bizfest_partner', null, [
            'url' => $url,
        ]);

        return response()->json([
            'success' => true,
            'url' => $url,
        ], 201);
    }

    private function format(BizfestPartner $partner): array
    {
        return [
            'id' => $partner->id,
            'programme' => $partner->programme,
            'name' => $partner->name,
            'label' => $partner->label,
            'logo_url' => $partner->logo_url,
            'website_url' => $partner->website_url,
            'sort_order' => $partner->sort_order,
            'is_active' => (bool) $partner->is_active,
            'created_at' => $partner->created_at?->toIso8601String(),
            'updated_at' => $partner->updated_at?->toIso8601String(),
        ];
    }
}
