<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Store;
use App\Models\StorefrontBuilderSession;
use App\Models\StorefrontTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class StorefrontTemplateAssignmentService
{
    public function __construct(
        private readonly ApiCacheService $cache,
    ) {}

    /**
     * Apply an active-status change and migrate / restore merchant template assignments.
     *
     * @return array{migrated: int, restored: int}
     */
    public function applyActiveStatus(StorefrontTemplate $template, bool $isActive): array
    {
        if ($template->is_active === $isActive) {
            return ['migrated' => 0, 'restored' => 0];
        }

        if (! $isActive && $template->id === StorefrontTemplate::DEFAULT_ID) {
            throw new InvalidArgumentException(
                'The default storefront template cannot be deactivated.',
            );
        }

        return DB::transaction(function () use ($template, $isActive) {
            $template->is_active = $isActive;
            $template->save();

            if (! $isActive) {
                return [
                    'migrated' => $this->migrateStoresAwayFrom($template->id),
                    'restored' => 0,
                ];
            }

            return [
                'migrated' => 0,
                'restored' => $this->restorePreferredTemplate($template->id),
            ];
        });
    }

    private function migrateStoresAwayFrom(string $templateId): int
    {
        $defaultId = StorefrontTemplate::DEFAULT_ID;
        $stores = Store::query()
            ->where('storefront_template_id', $templateId)
            ->get();

        foreach ($stores as $store) {
            $store->preferred_storefront_template_id = $templateId;
            $store->storefront_template_id = $defaultId;
            $this->alignStorefrontJsonTemplateId($store, $defaultId);
            $store->save();
            $this->cache->forgetStore($store);
        }

        $this->alignBuilderSessionsForStores(
            $stores->pluck('id'),
            $templateId,
            $defaultId,
        );

        return $stores->count();
    }

    private function restorePreferredTemplate(string $templateId): int
    {
        $defaultId = StorefrontTemplate::DEFAULT_ID;

        // Only restore merchants still on the forced default — if they picked
        // another active template while this one was off, leave that choice alone.
        $stores = Store::query()
            ->where('preferred_storefront_template_id', $templateId)
            ->where('storefront_template_id', $defaultId)
            ->get();

        foreach ($stores as $store) {
            $store->storefront_template_id = $templateId;
            $store->preferred_storefront_template_id = null;
            $this->alignStorefrontJsonTemplateId($store, $templateId);
            $store->save();
            $this->cache->forgetStore($store);
        }

        $this->alignBuilderSessionsForStores(
            $stores->pluck('id'),
            $defaultId,
            $templateId,
        );

        return $stores->count();
    }

    private function alignStorefrontJsonTemplateId(Store $store, string $templateId): void
    {
        foreach (['draft_json', 'published_json', 'storefront_content'] as $field) {
            $json = $store->{$field};
            if (! is_array($json) || $json === []) {
                continue;
            }

            data_set($json, 'template.id', $templateId);
            if (! data_get($json, 'template.source')) {
                data_set($json, 'template.source', 'platform_migrated');
            }
            $store->{$field} = $json;
        }
    }

    /**
     * @param  Collection<int, int|string>  $storeIds
     */
    private function alignBuilderSessionsForStores(
        Collection $storeIds,
        string $fromTemplateId,
        string $toTemplateId,
    ): void {
        if ($storeIds->isEmpty() || ! Schema::hasTable('storefront_builder_sessions')) {
            return;
        }

        $sessions = StorefrontBuilderSession::query()
            ->whereIn('store_id', $storeIds->all())
            ->where('selected_template_id', $fromTemplateId)
            ->get();

        foreach ($sessions as $session) {
            $session->selected_template_id = $toTemplateId;
            $snapshot = $session->storefront_snapshot;
            if (is_array($snapshot) && $snapshot !== []) {
                data_set($snapshot, 'template.id', $toTemplateId);
                $session->storefront_snapshot = $snapshot;
            }
            $session->save();
        }
    }
}
