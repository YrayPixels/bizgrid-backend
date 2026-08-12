<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\ProductStyleEnrichmentService;
use Illuminate\Console\Command;

class EnrichProductStyleProfiles extends Command
{
    protected $signature = 'storehause:enrich-style-profiles
        {store? : Store id or slug}
        {--force : Re-generate profiles even when present}
        {--limit=60 : Max products to enrich}';

    protected $description = 'Generate AI style_profile metadata for store products';

    public function handle(ProductStyleEnrichmentService $enrichment): int
    {
        $storeArg = $this->argument('store');
        $force = (bool) $this->option('force');
        $limit = max(1, (int) $this->option('limit'));

        $stores = Store::query()
            ->when($storeArg, function ($query) use ($storeArg) {
                $query->where('id', $storeArg)->orWhere('slug', $storeArg);
            })
            ->orderBy('id')
            ->get();

        if ($stores->isEmpty()) {
            $this->error('No matching stores found.');

            return self::FAILURE;
        }

        foreach ($stores as $store) {
            $updated = $enrichment->enrichStore($store, null, $limit, $force);
            $this->info("Store {$store->slug}: updated {$updated} products.");
        }

        return self::SUCCESS;
    }
}
