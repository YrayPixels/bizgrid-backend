<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class StorefrontTemplate extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'label',
        'description',
        'best_for',
        'preview',
        'is_active',
        'sort_order',
        'default_palette',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'default_palette' => 'array',
        ];
    }

    /** @return list<string> */
    public static function concreteIds(): array
    {
        return ['classic', 'editorial', 'bold_grid', 'fashion_lookbook', 'minimalistic', 'beauty', 'cosmetics'];
    }

    /** @return list<string> */
    public static function defaultActiveConcreteIds(): array
    {
        return ['fashion_lookbook', 'beauty', 'cosmetics', 'minimalistic'];
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
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'default_palette' => $this->default_palette,
        ];
    }
}
