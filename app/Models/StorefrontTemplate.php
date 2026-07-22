<?php

namespace App\Models;

use Database\Seeders\StorefrontTemplateSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class StorefrontTemplate extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** Default storefront template for new merchants. */
    public const DEFAULT_ID = 'minimalistic';

    protected $fillable = [
        'id',
        'label',
        'description',
        'best_for',
        'preview',
        'type',
        'is_active',
        'sort_order',
        'default_palette',
        'industries',
        'tone_tags',
        'visual_tags',
        'product_types',
        'required_content_slots',
        'optional_content_slots',
        'origin',
        'base_template_id',
        'generation_status',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'default_palette' => 'array',
            'industries' => 'array',
            'tone_tags' => 'array',
            'visual_tags' => 'array',
            'product_types' => 'array',
            'required_content_slots' => 'array',
            'optional_content_slots' => 'array',
        ];
    }

    /**
     * Ensure platform templates exist. Safe to call repeatedly — only seeds when empty.
     */
    public static function ensureSeeded(): void
    {
        if (! Schema::hasTable('storefront_templates')) {
            return;
        }

        if (self::query()->exists()) {
            return;
        }

        (new StorefrontTemplateSeeder)->run();
    }

    /** @return list<string> */
    public static function concreteIds(): array
    {
        return [
            'classic',
            'editorial',
            'bold_grid',
            'fashion_lookbook',
            'minimalistic',
            'beauty',
            'cosmetics',
            'furniture-hardware',
            'hair-and-fashion',
        ];
    }

    /** @return list<string> */
    public static function defaultActiveConcreteIds(): array
    {
        return [
            'minimalistic',
            'fashion_lookbook',
            'beauty',
            'cosmetics',
            'furniture-hardware',
            'hair-and-fashion',
        ];
    }

    /** @return list<string> */
    public static function activeConcreteIds(): array
    {
        if (! Schema::hasTable('storefront_templates')) {
            return self::defaultActiveConcreteIds();
        }

        $templates = self::query()
            ->orderBy('sort_order')
            ->pluck('is_active', 'id')
            ->all();

        if (! $templates) {
            return self::defaultActiveConcreteIds();
        }

        $activeIds = array_values(array_intersect(
            array_keys(array_filter($templates)),
            self::defaultActiveConcreteIds(),
        ));
        $missingBuiltIns = array_values(array_diff(self::defaultActiveConcreteIds(), array_keys($templates)));

        return array_values(array_unique(array_merge($activeIds, $missingBuiltIns)));
    }

    public function toCatalogArray(): array
    {
        return [
            'id' => $this->id,
            'value' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'bestFor' => $this->best_for,
            'preview' => $this->preview,
            'type' => $this->type ?? 'json',
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'default_palette' => $this->default_palette,
            'best_for' => $this->best_for ? array_map('trim', explode(',', $this->best_for)) : [],
            'industries' => $this->industries ?? [],
            'tone_tags' => $this->tone_tags ?? [],
            'visual_tags' => $this->visual_tags ?? [],
            'product_types' => $this->product_types ?? [],
            'required_content_slots' => $this->required_content_slots ?? [],
            'optional_content_slots' => $this->optional_content_slots ?? [],
            'origin' => $this->origin ?? 'platform',
            'base_template_id' => $this->base_template_id,
            'generation_status' => $this->generation_status ?? ($this->is_active ? 'active' : 'inactive'),
        ];
    }
}
