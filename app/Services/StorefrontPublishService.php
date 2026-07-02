<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StorefrontBuilderSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontPublishService
{
    /**
     * Bolt custom project payloads can exceed MySQL max_allowed_packet when duplicated
     * into stores.draft_json. Keep them on the builder session snapshot instead.
     *
     * @var list<string>
     */
    public const SESSION_ONLY_STOREFRONT_KEYS = ['custom_files', 'custom_code'];

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

    /** @return array<string, mixed>|null */
    public function resolveFullDraft(Store $store): ?array
    {
        $draft = $this->resolveDraft($store);
        if (! is_array($draft) || $draft === []) {
            return $draft;
        }

        return $this->mergeSessionOnlyKeys($draft, $this->findActiveSessionSnapshot($store));
    }

    public function assignDraft(Store $store, array $storefront): void
    {
        $store->draft_json = $this->stripSessionOnlyKeys($storefront);
    }

    public function persistDraft(Store $store, array $storefront): void
    {
        $this->assignDraft($store, $storefront);
        $this->reconnectAndSave($store);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $sessionSnapshot
     * @return array<string, mixed>
     */
    public function mergeSessionOnlyKeys(array $draft, ?array $sessionSnapshot): array
    {
        if (! is_array($sessionSnapshot)) {
            return $draft;
        }

        foreach (self::SESSION_ONLY_STOREFRONT_KEYS as $key) {
            if (array_key_exists($key, $sessionSnapshot)) {
                $draft[$key] = $sessionSnapshot[$key];
            }
        }

        return $draft;
    }

    /** @return array<string, mixed> */
    public function stripSessionOnlyKeys(array $storefront): array
    {
        $stripped = $storefront;
        foreach (self::SESSION_ONLY_STOREFRONT_KEYS as $key) {
            unset($stripped[$key]);
        }

        return $stripped;
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
        $draft = $this->resolveFullDraft($store);

        if (! $this->isPublished($store)) {
            return is_array($draft) && $draft !== [];
        }

        return json_encode($draft) !== json_encode($this->resolvePublished($store));
    }

    public function publish(Store $store): Store
    {
        $draft = $this->resolveFullDraft($store);

        if (! is_array($draft) || $draft === []) {
            throw ValidationException::withMessages([
                'storefront' => 'Create a storefront draft before publishing.',
            ]);
        }

        $this->reconnectAndSave($store->fill([
            'published_json' => $draft,
            'status' => 'published',
            'published_at' => now(),
        ]));

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

    /** @return array<string, mixed>|null */
    private function findActiveSessionSnapshot(Store $store): ?array
    {
        $snapshot = StorefrontBuilderSession::query()
            ->where('store_id', $store->id)
            ->whereNotIn('status', ['published'])
            ->latest('updated_at')
            ->value('storefront_snapshot');

        return is_array($snapshot) ? $snapshot : null;
    }

    private function reconnectAndSave(Store $store): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::reconnect();
        }

        $store->save();
    }
}
