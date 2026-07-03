<?php

namespace App\Services;

use App\Models\Store;
use App\Models\StorefrontBuilderSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontPublishService
{
    public function __construct(
        private readonly WorkbenchProjectStorage $projectStorage,
    ) {}
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

    /**
     * Drop redundant legacy custom_code when multi-file custom_files are present.
     * Duplicating both can exceed MySQL max_allowed_packet on session saves.
     *
     * @param  array<string, mixed>  $storefront
     * @return array<string, mixed>
     */
    public function compactSessionSnapshot(array $storefront): array
    {
        $compact = $storefront;

        if (
            ! empty($compact['custom_files'])
            && is_array($compact['custom_files'])
            && count($compact['custom_files']) > 0
        ) {
            unset($compact['custom_code']);
        }

        return $compact;
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
        $session = StorefrontBuilderSession::query()
            ->where('store_id', $store->id)
            ->whereNotIn('status', ['published'])
            ->latest('updated_at')
            ->first(['id', 'storefront_snapshot']);

        if (! $session || ! is_array($session->storefront_snapshot)) {
            return null;
        }

        return $this->projectStorage->hydrateSnapshot(
            $session->storefront_snapshot,
            (int) $session->id,
        );
    }

    private function reconnectAndSave(Store $store): void
    {
        $this->reconnectAndSaveModel($store);
    }

    public function reconnectAndSaveModel(Model $model): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::reconnect();
        }

        try {
            $model->save();
        } catch (QueryException $exception) {
            if (! $this->isMysqlGoneAway($exception)) {
                throw $exception;
            }

            DB::reconnect();
            $model->save();
        }
    }

    private function isMysqlGoneAway(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, '2006')
            || str_contains($message, 'MySQL server has gone away');
    }
}
