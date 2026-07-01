<?php

use App\Evals\EvalCase;

return [
    new EvalCase(
        name: 'Handmade soy candles shop',
        input: [
            'message' => 'I sell handmade soy candles. Warm, cozy, gift-friendly.',
            'current_profile' => [],
            'conversation_history' => [],
        ],
        expected: [],
        assertions: [
            'has_business_name' => fn (array $r) => filled($r['business_name'] ?? null),
            'has_description' => fn (array $r) => filled($r['description'] ?? null),
            'industry_valid' => fn (array $r) => in_array($r['industry'] ?? null, [
                'home_and_living', 'other', null,
            ], true),
            'tone_array' => fn (array $r) => is_array($r['tone'] ?? null) && count($r['tone']) > 0,
        ],
    ),

    new EvalCase(
        name: 'Lagos fashion boutique',
        input: [
            'message' => 'Urban streetwear brand based in Lagos. Bold prints, African-inspired designs.',
            'current_profile' => [],
            'conversation_history' => [],
        ],
        expected: [],
        assertions: [
            'has_business_name' => fn (array $r) => filled($r['business_name'] ?? null),
            'description_mentions_fashion' => fn (array $r) => stripos($r['description'] ?? '', 'fashion') !== false
                || stripos($r['description'] ?? '', 'streetwear') !== false
                || stripos($r['description'] ?? '', 'clothing') !== false,
            'industry_is_fashion' => fn (array $r) => ($r['industry'] ?? null) === 'fashion_and_apparel',
            'tone_has_bold_or_urban' => fn (array $r) => collect($r['tone'] ?? [])->contains(
                fn (string $t) => in_array(strtolower($t), ['bold', 'urban', 'vibrant', 'edgy', 'street'], true),
            ),
        ],
    ),

    new EvalCase(
        name: 'Organic skincare brand',
        input: [
            'message' => 'Glow Rituals — organic skincare for busy professionals. Natural ingredients, simple routines.',
            'current_profile' => [],
            'conversation_history' => [],
        ],
        expected: [],
        assertions: [
            'business_name_detected' => fn (array $r) => stripos($r['business_name'] ?? '', 'glow') !== false,
            'industry_is_beauty' => fn (array $r) => ($r['industry'] ?? null) === 'beauty_and_skincare',
            'tone_has_natural' => fn (array $r) => collect($r['tone'] ?? [])->contains(
                fn (string $t) => in_array(strtolower($t), ['natural', 'organic', 'clean', 'minimal', 'simple'], true),
            ),
        ],
    ),

    new EvalCase(
        name: 'Tech gadget reseller',
        input: [
            'message' => 'Premium phone accessories — cases, chargers, screen protectors. Fast shipping across Nigeria.',
            'current_profile' => [],
            'conversation_history' => [],
        ],
        expected: [],
        assertions: [
            'industry_is_electronics' => fn (array $r) => ($r['industry'] ?? null) === 'electronics',
            'description_non_empty' => fn (array $r) => strlen($r['description'] ?? '') > 20,
        ],
    ),

    new EvalCase(
        name: 'Bakery and cakes',
        input: [
            'message' => 'Homemade cakes and pastries. Custom orders for birthdays and weddings.',
            'current_profile' => [],
            'conversation_history' => [],
        ],
        expected: [],
        assertions: [
            'industry_is_food' => fn (array $r) => ($r['industry'] ?? null) === 'food_and_beverage',
            'tone_has_warm_or_sweet' => fn (array $r) => collect($r['tone'] ?? [])->contains(
                fn (string $t) => in_array(strtolower($t), ['warm', 'sweet', 'cozy', 'homemade', 'artisanal'], true),
            ),
        ],
    ),

    new EvalCase(
        name: 'Profile merges with existing data',
        input: [
            'message' => 'We also offer delivery.',
            'current_profile' => [
                'business_name' => 'Cake Factory',
                'industry' => 'food_and_beverage',
                'description' => 'We bake custom cakes.',
                'tone' => ['warm', 'homemade'],
            ],
            'conversation_history' => [],
        ],
        expected: [],
        assertions: [
            'preserves_business_name' => fn (array $r) => ($r['business_name'] ?? null) === 'Cake Factory',
            'preserves_industry' => fn (array $r) => ($r['industry'] ?? null) === 'food_and_beverage',
        ],
    ),
];
