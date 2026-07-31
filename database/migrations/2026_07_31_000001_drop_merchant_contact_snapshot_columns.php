<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('merchants')) {
            return;
        }

        // SQLite cannot drop an indexed column until the index is removed.
        $this->dropMerchantEmailIndex();

        $columns = collect(['contact_name', 'email', 'phone'])
            ->filter(fn (string $column) => Schema::hasColumn('merchants', $column))
            ->values()
            ->all();

        if ($columns === []) {
            return;
        }

        Schema::table('merchants', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('merchants')) {
            return;
        }

        Schema::table('merchants', function (Blueprint $table) {
            if (! Schema::hasColumn('merchants', 'contact_name')) {
                $table->string('contact_name', 120)->nullable()->after('slug');
            }
            if (! Schema::hasColumn('merchants', 'email')) {
                $table->string('email')->nullable()->index()->after('contact_name');
            }
            if (! Schema::hasColumn('merchants', 'phone')) {
                $table->string('phone', 40)->nullable()->after('email');
            }
        });
    }

    private function dropMerchantEmailIndex(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = collect(DB::select('PRAGMA index_list(merchants)'))
                ->pluck('name')
                ->filter(fn ($name) => is_string($name) && str_contains($name, 'email'))
                ->values();

            foreach ($indexes as $index) {
                DB::statement("DROP INDEX IF EXISTS \"{$index}\"");
            }

            return;
        }

        try {
            Schema::table('merchants', function (Blueprint $table) {
                $table->dropIndex(['email']);
            });
        } catch (\Throwable) {
            // Index may already be absent on some environments.
        }
    }
};
