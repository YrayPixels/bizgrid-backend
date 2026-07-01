<?php

use App\Evals\EvalCase;

return [
    new EvalCase(
        name: 'Skincare brand picks cosmetics template',
        input: [
            'message' => 'I sell organic face serums and moisturizers.',
            'business_name' => 'Glow Lab',
            'industry' => 'beauty_and_skincare',
            'description' => 'Clean, science-backed skincare.',
            'available_template_ids' => ['cosmetics', 'beauty', 'minimalistic', 'fashion_lookbook'],
            'template_catalog' => [
                ['id' => 'cosmetics', 'label' => 'Cosmetics', 'industries' => ['beauty']],
                ['id' => 'beauty', 'label' => 'Beauty', 'industries' => ['beauty']],
                ['id' => 'minimalistic', 'label' => 'Minimalistic', 'industries' => ['home', 'wellness']],
                ['id' => 'fashion_lookbook', 'label' => 'Fashion', 'industries' => ['fashion']],
            ],
        ],
        expected: [],
        assertions: [
            'template_is_cosmetics_or_beauty' => fn (array $r) => in_array($r['template_id'] ?? null, ['cosmetics', 'beauty'], true),
            'has_valid_brand_color' => fn (array $r) => preg_match('/^#[0-9A-Fa-f]{6}$/', $r['brand_color'] ?? '') === 1,
            'has_palette' => fn (array $r) => is_array($r['palette'] ?? null) && count($r['palette']) > 0,
            'has_merchant_summary' => fn (array $r) => filled($r['merchant_summary'] ?? null),
            'summary_no_jargon' => fn (array $r) => stripos($r['merchant_summary'] ?? '', 'template') === false
                && stripos($r['merchant_summary'] ?? '', 'theme') === false,
        ],
    ),

    new EvalCase(
        name: 'Clothing brand picks fashion template',
        input: [
            'message' => 'Luxury streetwear. Limited drops, bold graphics.',
            'business_name' => 'Drop Culture',
            'industry' => 'fashion_and_apparel',
            'description' => 'Exclusive streetwear collections.',
            'available_template_ids' => ['cosmetics', 'beauty', 'minimalistic', 'fashion_lookbook'],
            'template_catalog' => [
                ['id' => 'cosmetics', 'label' => 'Cosmetics', 'industries' => ['beauty']],
                ['id' => 'beauty', 'label' => 'Beauty', 'industries' => ['beauty']],
                ['id' => 'minimalistic', 'label' => 'Minimalistic', 'industries' => ['home', 'wellness']],
                ['id' => 'fashion_lookbook', 'label' => 'Fashion', 'industries' => ['fashion']],
            ],
        ],
        expected: [],
        assertions: [
            'template_is_fashion' => fn (array $r) => ($r['template_id'] ?? null) === 'fashion_lookbook',
        ],
    ),

    new EvalCase(
        name: 'Wellness brand picks minimalistic',
        input: [
            'message' => 'Wellness coaching and mindfulness products. Calm, peaceful brand.',
            'business_name' => 'Mindful Living',
            'industry' => 'services',
            'description' => 'Mindfulness coaching and wellness products.',
            'available_template_ids' => ['cosmetics', 'beauty', 'minimalistic', 'fashion_lookbook'],
            'template_catalog' => [
                ['id' => 'cosmetics', 'label' => 'Cosmetics', 'industries' => ['beauty']],
                ['id' => 'beauty', 'label' => 'Beauty', 'industries' => ['beauty']],
                ['id' => 'minimalistic', 'label' => 'Minimalistic', 'industries' => ['home', 'wellness']],
                ['id' => 'fashion_lookbook', 'label' => 'Fashion', 'industries' => ['fashion']],
            ],
        ],
        expected: [],
        assertions: [
            'template_is_minimalistic' => fn (array $r) => ($r['template_id'] ?? null) === 'minimalistic',
        ],
    ),
];
