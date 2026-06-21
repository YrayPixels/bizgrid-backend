<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Validation\ValidationException;

class StorefrontPublishService
{
    /** @return array<string, mixed>|null */
    public function resolveDraft(Store $store): ?array
    {
        if (is_array($store->draft_json) && $store->draft_json !== []) {
            return $store->draft_json;
        }

        if (is_array($store->storefront_content) && $store->storefront_content !== []) {
            return $store->storefront_content;
        }

        return null;
    }

    public function assignDraft(Store $store, array $storefront): void
    {
        $store->draft_json = $storefront;
    }

    /** @return array<string, mixed>|null */
    public function resolvePublished(Store $store): ?array
    {
        if (! is_array($store->published_json) || $store->published_json === []) {
            return null;
        }

        return $store->published_json;
    }

    public function isPublished(Store $store): bool
    {
        return $store->status === 'published' && $this->resolvePublished($store) !== null;
    }

    public function hasUnpublishedChanges(Store $store): bool
    {
        $draft = $this->resolveDraft($store);

        if (! $this->isPublished($store)) {
            return is_array($draft) && $draft !== [];
        }

        return json_encode($draft) !== json_encode($this->resolvePublished($store));
    }

    public function publish(Store $store): Store
    {
        $draft = $this->resolveDraft($store);

        if (! is_array($draft) || $draft === []) {
            throw ValidationException::withMessages([
                'storefront' => 'Create a storefront draft before publishing.',
            ]);
        }

        $store->published_json = $draft;
        $store->status = 'published';
        $store->published_at = now();
        $store->save();

        return $store->fresh();
    }

    /** @return array{status: string, published_at: string|null, is_published: bool, has_unpublished_changes: bool} */
    public function publishMeta(Store $store): array
    {
        return [
            'status' => (string) ($store->status ?? 'draft'),
            'published_at' => $store->published_at?->toIso8601String(),
            'is_published' => $this->isPublished($store),
            'has_unpublished_changes' => $this->hasUnpublishedChanges($store),
        ];
    }
}
