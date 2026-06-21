<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StoreCategory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreCategoryService
{
    /** @return list<array<string, mixed>> */
    public function listForStore(Store $store): array
    {
        return StoreCategory::query()
            ->where('store_id', $store->id)
            ->with('parent:id,name,slug')
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (StoreCategory $category) => $this->format($category))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $data */
    public function createForStore(Store $store, array $data): StoreCategory
    {
        $name = trim((string) ($data['name'] ?? ''));
        $slug = $this->uniqueSlug(
            $store,
            ! empty($data['slug']) ? Str::slug((string) $data['slug']) : Str::slug($name),
        );

        $parentId = $this->resolveParentId($store, $data['parent_id'] ?? null);

        return StoreCategory::create([
            'store_id' => $store->id,
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'sort_order' => (int) StoreCategory::where('store_id', $store->id)->max('sort_order') + 1,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateCategory(StoreCategory $category, array $data): StoreCategory
    {
        $store = $category->store;

        if (array_key_exists('name', $data)) {
            $category->name = trim((string) $data['name']);
        }

        if (array_key_exists('slug', $data) && $data['slug'] !== null && $data['slug'] !== '') {
            $category->slug = $this->uniqueSlug($store, Str::slug((string) $data['slug']), $category->id);
        } elseif (array_key_exists('name', $data)) {
            $category->slug = $this->uniqueSlug($store, Str::slug($category->name), $category->id);
        }

        if (array_key_exists('parent_id', $data)) {
            $category->parent_id = $this->resolveParentId($store, $data['parent_id'], $category->id);
        }

        if (array_key_exists('sort_order', $data)) {
            $category->sort_order = (int) $data['sort_order'];
        }

        $category->save();

        return $category->fresh(['parent'])->loadCount('products');
    }

    public function deleteCategory(StoreCategory $category): void
    {
        if ($category->products()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Remove or reassign products before deleting this category.',
            ]);
        }

        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category' => 'Remove or reassign child categories before deleting this category.',
            ]);
        }

        $category->delete();
    }

    public function findOrCreateByName(Store $store, string $name): ?StoreCategory
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }

        $existing = StoreCategory::query()
            ->where('store_id', $store->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
            ->first();

        if ($existing) {
            return $existing;
        }

        return $this->createForStore($store, ['name' => $trimmed]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{category_id: ?string, category: ?string}
     */
    public function resolveProductCategory(Store $store, array $data): array
    {
        if (array_key_exists('category_id', $data)) {
            if ($data['category_id'] === null || $data['category_id'] === '') {
                return ['category_id' => null, 'category' => null];
            }

            $category = StoreCategory::query()
                ->where('store_id', $store->id)
                ->findOrFail((string) $data['category_id']);

            return [
                'category_id' => $category->id,
                'category' => $category->name,
            ];
        }

        if (! array_key_exists('category', $data)) {
            return ['category_id' => null, 'category' => null];
        }

        $categoryName = trim((string) ($data['category'] ?? ''));
        if ($categoryName === '') {
            return ['category_id' => null, 'category' => null];
        }

        $category = $this->findOrCreateByName($store, $categoryName);

        return [
            'category_id' => $category?->id,
            'category' => $category?->name ?? $categoryName,
        ];
    }

    /** @return array<string, mixed> */
    public function format(StoreCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
            'parent_name' => $category->parent?->name,
            'sort_order' => $category->sort_order,
            'products_count' => (int) ($category->products_count ?? $category->products()->count()),
        ];
    }

    private function uniqueSlug(Store $store, string $slug, ?string $ignoreId = null): string
    {
        $base = $slug !== '' ? $slug : 'category';
        $candidate = $base;
        $suffix = 2;

        while (
            StoreCategory::query()
                ->where('store_id', $store->id)
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function resolveParentId(Store $store, mixed $parentId, ?string $categoryId = null): ?string
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parentId = (string) $parentId;

        if ($categoryId !== null && $parentId === $categoryId) {
            throw ValidationException::withMessages([
                'parent_id' => 'A category cannot be its own parent.',
            ]);
        }

        $parent = StoreCategory::query()
            ->where('store_id', $store->id)
            ->findOrFail($parentId);

        if ($categoryId !== null && $parent->parent_id === $categoryId) {
            throw ValidationException::withMessages([
                'parent_id' => 'Choose a valid parent category.',
            ]);
        }

        return $parent->id;
    }
}
