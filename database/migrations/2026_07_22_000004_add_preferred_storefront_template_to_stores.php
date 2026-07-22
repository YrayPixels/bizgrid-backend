<?php

use App\Models\StorefrontTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        if (! Schema::hasColumn('stores', 'preferred_storefront_template_id')) {
            Schema::table('stores', function (Blueprint $table) {
                $table->string('preferred_storefront_template_id', 60)
                    ->nullable()
                    ->after('storefront_template_id');
            });
        }

        if (! Schema::hasTable('storefront_templates')) {
            return;
        }

        $defaultId = StorefrontTemplate::DEFAULT_ID;
        $inactiveIds = DB::table('storefront_templates')
            ->where('is_active', false)
            ->where('id', '!=', $defaultId)
            ->pluck('id')
            ->all();

        if ($inactiveIds === []) {
            return;
        }

        $stores = DB::table('stores')
            ->whereIn('storefront_template_id', $inactiveIds)
            ->get(['id', 'storefront_template_id', 'draft_json', 'published_json', 'storefront_content']);

        foreach ($stores as $store) {
            $updates = [
                'preferred_storefront_template_id' => $store->storefront_template_id,
                'storefront_template_id' => $defaultId,
            ];

            foreach (['draft_json', 'published_json', 'storefront_content'] as $field) {
                $raw = $store->{$field} ?? null;
                if (! is_string($raw) || $raw === '') {
                    continue;
                }
                $json = json_decode($raw, true);
                if (! is_array($json)) {
                    continue;
                }
                data_set($json, 'template.id', $defaultId);
                $updates[$field] = json_encode($json);
            }

            DB::table('stores')->where('id', $store->id)->update($updates);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stores')) {
            return;
        }

        if (! Schema::hasColumn('stores', 'preferred_storefront_template_id')) {
            return;
        }

        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('preferred_storefront_template_id');
        });
    }
};
