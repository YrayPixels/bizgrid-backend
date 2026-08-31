<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BizfestApplication;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BizfestApplicationController extends Controller
{
    public const CATEGORIES = [
        'Fashion',
        'Beauty & cosmetics',
        'Food & beverage',
        'Electronics',
        'Home & lifestyle',
        'Accessories',
        'Services',
        'Retail',
        'Other',
    ];

    public const HOW_HEARD = [
        'Instagram',
        'Facebook',
        'TikTok',
        'WhatsApp',
        'Google',
        'Friend / referral',
        'Event / flyer',
        'Other',
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'owner_name' => 'required|string|max:160',
            'business_name' => 'required|string|max:200',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:40',
            'category' => ['required', 'string', Rule::in(self::CATEGORIES)],
            'city' => 'required|string|max:120',
            'what_you_sell' => 'required|string|max:2000',
            'sell_channels' => 'required|string|max:255',
            'unique_value' => 'required|string|max:2000',
            'online_presence_url' => 'nullable|url|max:500',
            'how_heard' => ['required', 'string', Rule::in(self::HOW_HEARD)],
            'team_type' => ['required', 'string', Rule::in(['solo', 'team'])],
            'utm_source' => 'nullable|string|max:80',
            'utm_medium' => 'nullable|string|max:80',
            'utm_campaign' => 'nullable|string|max:120',
            'programme' => 'nullable|string|max:40',
        ]);

        $programme = $data['programme'] ?? 'bizfest-1';
        $email = Str::lower(trim($data['email']));

        $existing = BizfestApplication::query()
            ->where('programme', $programme)
            ->where('email', $email)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'An application with this email already exists for BizFest.',
            ], 422);
        }

        $application = BizfestApplication::create([
            'programme' => $programme,
            'owner_name' => $data['owner_name'],
            'business_name' => $data['business_name'],
            'email' => $email,
            'phone' => $data['phone'],
            'category' => $data['category'],
            'city' => $data['city'],
            'what_you_sell' => $data['what_you_sell'],
            'sell_channels' => $data['sell_channels'],
            'unique_value' => $data['unique_value'],
            'online_presence_url' => $data['online_presence_url'] ?? null,
            'how_heard' => $data['how_heard'],
            'team_type' => $data['team_type'],
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'status' => 'new',
        ]);

        $this->matchStore($application);

        return response()->json([
            'success' => true,
            'message' => 'Application submitted successfully.',
            'data' => [
                'id' => $application->id,
                'has_store' => $application->has_store,
            ],
        ], 201);
    }

    public static function matchStore(BizfestApplication $application): void
    {
        $email = Str::lower($application->email);

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $merchant = $user?->merchant;
        $store = $merchant?->stores()->orderByDesc('updated_at')->first();

        if (! $store) {
            $store = Store::query()
                ->whereRaw('LOWER(contact_email) = ?', [$email])
                ->orderByDesc('updated_at')
                ->first();
            $merchant = $store?->merchant;
            $user = $merchant?->owner;
        }

        if (! $store) {
            $application->forceFill([
                'user_id' => $user?->id,
                'merchant_id' => $merchant?->id,
                'store_id' => null,
                'has_store' => false,
                'store_published' => false,
                'matched_at' => null,
            ])->save();

            return;
        }

        $published = filled($store->published_at)
            || in_array($store->status, ['published', 'live', 'active'], true);

        $application->forceFill([
            'user_id' => $user?->id,
            'merchant_id' => $merchant?->id ?? $store->merchant_id,
            'store_id' => $store->id,
            'has_store' => true,
            'store_published' => (bool) $published,
            'matched_at' => now(),
        ])->save();
    }
}
