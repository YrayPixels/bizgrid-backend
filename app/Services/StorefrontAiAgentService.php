<?php

namespace App\Services;

use App\Agents\AgentRegistry;
use App\Models\Store;

class StorefrontAiAgentService
{
    public function __construct(
        private readonly AgentRegistry $registry,
    ) {}

    public function available(): bool
    {
        return $this->registry->available();
    }

    /**
     * @param  array<string, mixed>  $baseStorefront
     * @return array<string, mixed>|null
     */
    public function synthesizeStorefront(Store $store, array $baseStorefront): ?array
    {
        return $this->registry->execute('storefront-writer', [
            'store' => $store,
            'base_storefront' => $baseStorefront,
        ]);
    }
}
