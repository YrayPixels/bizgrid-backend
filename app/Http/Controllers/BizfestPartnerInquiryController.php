<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BizfestPartnerInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BizfestPartnerInquiryController extends Controller
{
    public const INQUIRY_TYPES = ['sponsor', 'partner'];

    public const INTERESTS = [
        'sponsorship',
        'expo_booth',
        'exhibition_space',
    ];

    public const SPONSOR_TIERS = [
        'Title sponsor',
        'Gold sponsor',
        'Silver sponsor',
        'Community partner',
        'Not sure — want to discuss',
    ];

    public const BOOTH_PACKAGES = [
        'Standard booth',
        'Premium booth',
        'Corner booth',
        'Not sure — send me options',
    ];

    public const SPACE_PACKAGES = [
        'Brand wall / backdrop',
        'Demo zone',
        'Lounge / seating area',
        'Not sure — send me options',
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'inquiry_type' => ['required', 'string', Rule::in(self::INQUIRY_TYPES)],
            'company_name' => 'required|string|max:200',
            'contact_name' => 'required|string|max:160',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:40',
            'tier_interest' => ['nullable', 'string', Rule::in(self::SPONSOR_TIERS)],
            'interests' => ['nullable', 'array'],
            'interests.*' => ['string', Rule::in(self::INTERESTS)],
            'booth_package' => ['nullable', 'string', Rule::in(self::BOOTH_PACKAGES)],
            'space_package' => ['nullable', 'string', Rule::in(self::SPACE_PACKAGES)],
            'booth_quantity' => 'nullable|integer|min:1|max:50',
            'message' => 'nullable|string|max:2000',
            'utm_source' => 'nullable|string|max:80',
            'utm_medium' => 'nullable|string|max:80',
            'utm_campaign' => 'nullable|string|max:120',
            'programme' => 'nullable|string|max:40',
        ]);

        $programme = $data['programme'] ?? 'bizfest-1';
        $inquiryType = $data['inquiry_type'];
        $interests = $this->normalizeInterests($data['interests'] ?? [], $inquiryType);

        if ($inquiryType === 'sponsor' && $interests === []) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one sponsorship or expo option.',
                'errors' => [
                    'interests' => ['Select at least one sponsorship or expo option.'],
                ],
            ], 422);
        }

        $tierInterest = null;
        $boothPackage = null;
        $spacePackage = null;
        $boothQuantity = null;

        if ($inquiryType === 'sponsor') {
            if (in_array('sponsorship', $interests, true)) {
                $tierInterest = $data['tier_interest'] ?? null;
            }
            if (in_array('expo_booth', $interests, true)) {
                $boothPackage = $data['booth_package'] ?? null;
                $boothQuantity = $data['booth_quantity'] ?? null;
            }
            if (in_array('exhibition_space', $interests, true)) {
                $spacePackage = $data['space_package'] ?? null;
            }
        }

        $inquiry = BizfestPartnerInquiry::create([
            'programme' => $programme,
            'inquiry_type' => $inquiryType,
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'],
            'email' => Str::lower(trim($data['email'])),
            'phone' => $data['phone'],
            'tier_interest' => $tierInterest,
            'interests' => $interests !== [] ? $interests : null,
            'booth_package' => $boothPackage,
            'space_package' => $spacePackage,
            'booth_quantity' => $boothQuantity,
            'message' => $data['message'] ?? null,
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'status' => 'new',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Thanks — we\'ll follow up shortly.',
            'data' => [
                'id' => $inquiry->id,
            ],
        ], 201);
    }

    /**
     * @param  list<string>  $interests
     * @return list<string>
     */
    private function normalizeInterests(array $interests, string $inquiryType): array
    {
        if ($inquiryType !== 'sponsor') {
            return [];
        }

        $normalized = array_values(array_unique(array_filter(
            $interests,
            fn (string $interest) => in_array($interest, self::INTERESTS, true),
        )));

        return $normalized;
    }
}
