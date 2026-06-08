<?php

namespace Database\Seeders;

use App\Models\StorefrontTemplate;
use Illuminate\Database\Seeder;

class StorefrontTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'id' => 'classic',
                'label' => 'Classic Commerce',
                'description' => 'A balanced storefront with a clear hero, featured products, and trust blocks.',
                'best_for' => 'Most shops',
                'preview' => 'balanced',
                'sort_order' => 10,
                'default_palette' => [
                    'primary' => '#1F6F5B',
                    'accent' => '#F4B860',
                    'background' => '#FFFFFF',
                    'surface' => '#F7FAF8',
                    'text' => '#10201B',
                    'muted' => '#64736E',
                    'border' => '#DCE7E1',
                ],
            ],
            [
                'id' => 'editorial',
                'label' => 'Editorial Brand',
                'description' => 'A more premium, story-led layout for lifestyle and beauty businesses.',
                'best_for' => 'Fashion, beauty, home',
                'preview' => 'editorial',
                'sort_order' => 20,
                'default_palette' => [
                    'primary' => '#7C3A2D',
                    'accent' => '#D8A48F',
                    'background' => '#FFFFFF',
                    'surface' => '#F8F3F0',
                    'text' => '#241613',
                    'muted' => '#75615B',
                    'border' => '#E8DAD5',
                ],
            ],
            [
                'id' => 'fashion_lookbook',
                'label' => 'Fashion',
                'description' => 'A clothing-brand homepage with campaign imagery, curated edits, and product drops.',
                'best_for' => 'Clothing brands',
                'preview' => 'lookbook',
                'sort_order' => 30,
                'default_palette' => [
                    'primary' => '#111111',
                    'accent' => '#80131B',
                    'background' => '#FFFFFF',
                    'surface' => '#EEF0EF',
                    'text' => '#111111',
                    'muted' => '#6E6E6E',
                    'border' => '#E3E3E3',
                ],
            ],
            [
                'id' => 'beauty',
                'label' => 'Beauty',
                'description' => 'A polished beauty storefront for hair, skincare, bundles, and best-seller storytelling.',
                'best_for' => 'Beauty, hair, skincare',
                'preview' => 'beauty',
                'sort_order' => 40,
                'default_palette' => [
                    'primary' => '#6F2F2B',
                    'accent' => '#E6A79F',
                    'background' => '#FFF7F3',
                    'surface' => '#FFFFFF',
                    'text' => '#211313',
                    'muted' => '#80615C',
                    'border' => '#F0D6D0',
                ],
            ],
            [
                'id' => 'cosmetics',
                'label' => 'Cosmetics',
                'description' => 'A clean cosmetics storefront for skincare, serums, product storytelling, and ingredient-led trust.',
                'best_for' => 'Cosmetics, skincare',
                'preview' => 'cosmetics',
                'sort_order' => 45,
                'default_palette' => [
                    'primary' => '#82934C',
                    'accent' => '#F7E7D3',
                    'background' => '#FFFFFF',
                    'surface' => '#F4F6F1',
                    'text' => '#172012',
                    'muted' => '#6E7564',
                    'border' => '#E2E6D9',
                ],
            ],
            [
                'id' => 'minimalistic',
                'label' => 'Minimalistic',
                'description' => 'A clean supplement-inspired storefront with soft neutrals, rounded product cards, and wellness storytelling.',
                'best_for' => 'Wellness brands',
                'preview' => 'minimal',
                'sort_order' => 50,
                'default_palette' => [
                    'primary' => '#073E3F',
                    'accent' => '#D99359',
                    'background' => '#FBFBDC',
                    'surface' => '#FFFFFF',
                    'text' => '#073E3F',
                    'muted' => '#5F7A6F',
                    'border' => '#D8DEC1',
                ],
            ],
            [
                'id' => 'bold_grid',
                'label' => 'Bold Product Grid',
                'description' => 'A product-forward template with stronger catalog emphasis.',
                'best_for' => 'Electronics, food, high-volume catalog',
                'preview' => 'grid',
                'sort_order' => 60,
                'default_palette' => [
                    'primary' => '#0F4C81',
                    'accent' => '#F59E0B',
                    'background' => '#FFFFFF',
                    'surface' => '#F3F7FB',
                    'text' => '#102033',
                    'muted' => '#607085',
                    'border' => '#DCE7F2',
                ],
            ],
        ];

        foreach ($templates as $template) {
            StorefrontTemplate::updateOrCreate(
                ['id' => $template['id']],
                array_merge(['is_active' => true], $template),
            );
        }
    }
}
