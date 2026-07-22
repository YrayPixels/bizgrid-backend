<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_customers')) {
            Schema::create('store_customers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('email');
                $table->string('phone', 40)->nullable();
                $table->string('name', 160);
                $table->unsignedInteger('orders_count')->default(0);
                $table->decimal('total_spent', 14, 2)->default(0);
                $table->timestamp('first_order_at')->nullable();
                $table->timestamp('last_order_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['store_id', 'email']);
                $table->index(['store_id', 'last_order_at']);
            });
        }

        if (! Schema::hasTable('store_order_items')) {
            Schema::create('store_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_order_id')->constrained('store_orders')->cascadeOnDelete();
                $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
                $table->string('product_id', 120)->nullable()->index();
                $table->string('name');
                $table->unsignedInteger('quantity');
                $table->decimal('unit_price', 14, 2);
                $table->decimal('compare_at_price', 14, 2)->nullable();
                $table->string('discount_label', 160)->nullable();
                $table->decimal('line_total', 14, 2);
                $table->string('currency', 10)->default('NGN');
                $table->string('image_url', 2048)->nullable();
                $table->json('selected_options')->nullable();
                $table->timestamps();

                $table->index(['store_id', 'product_id']);
            });
        }

        Schema::table('store_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('store_orders', 'store_customer_id')) {
                $table->foreignId('store_customer_id')
                    ->nullable()
                    ->after('store_id')
                    ->constrained('store_customers')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('store_orders', 'invoice_number')) {
                $table->string('invoice_number', 40)->nullable()->unique()->after('order_number');
            }
        });

        DB::table('store_orders')->orderBy('id')->chunkById(100, function ($orders) use (&$customerCache) {
            foreach ($orders as $order) {
                $email = strtolower(trim((string) $order->customer_email));
                $customerId = null;

                if ($email !== '') {
                    $key = $order->store_id.'|'.$email;
                    if (! isset($customerCache[$key])) {
                        $existing = DB::table('store_customers')
                            ->where('store_id', $order->store_id)
                            ->where('email', $email)
                            ->value('id');

                        $customerCache[$key] = $existing ?: DB::table('store_customers')->insertGetId([
                            'store_id' => $order->store_id,
                            'email' => $email,
                            'phone' => $order->customer_phone,
                            'name' => $order->customer_name ?: 'Customer',
                            'orders_count' => 0,
                            'total_spent' => 0,
                            'first_order_at' => $order->placed_at,
                            'last_order_at' => $order->placed_at,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $customerId = $customerCache[$key];
                }

                DB::table('store_orders')->where('id', $order->id)->update([
                    'store_customer_id' => $customerId,
                    'invoice_number' => $order->invoice_number ?: ('INV-'.$order->id),
                ]);

                if (DB::table('store_order_items')->where('store_order_id', $order->id)->exists()) {
                    continue;
                }

                $items = is_string($order->items)
                    ? json_decode($order->items, true)
                    : [];

                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    DB::table('store_order_items')->insert([
                        'store_order_id' => $order->id,
                        'store_id' => $order->store_id,
                        'product_id' => isset($line['product_id']) ? (string) $line['product_id'] : null,
                        'name' => (string) ($line['name'] ?? 'Product'),
                        'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                        'unit_price' => (float) ($line['unit_price'] ?? 0),
                        'compare_at_price' => array_key_exists('compare_at_price', $line) && $line['compare_at_price'] !== null
                            ? (float) $line['compare_at_price']
                            : null,
                        'discount_label' => $line['discount_label'] ?? null,
                        'line_total' => (float) ($line['total'] ?? (($line['unit_price'] ?? 0) * ($line['quantity'] ?? 1))),
                        'currency' => (string) ($line['currency'] ?? 'NGN'),
                        'image_url' => $line['image_url'] ?? null,
                        'selected_options' => isset($line['selected_options'])
                            ? json_encode($line['selected_options'])
                            : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        foreach (DB::table('store_customers')->pluck('id') as $customerId) {
            $agg = DB::table('store_orders')
                ->where('store_customer_id', $customerId)
                ->selectRaw('COUNT(*) as orders_count')
                ->selectRaw("COALESCE(SUM(CASE WHEN status != 'cancelled' AND payment_status != 'refunded' THEN total_amount ELSE 0 END), 0) as total_spent")
                ->selectRaw('MIN(placed_at) as first_order_at')
                ->selectRaw('MAX(placed_at) as last_order_at')
                ->first();

            $latest = DB::table('store_orders')
                ->where('store_customer_id', $customerId)
                ->orderByDesc('placed_at')
                ->first(['customer_name', 'customer_phone']);

            DB::table('store_customers')->where('id', $customerId)->update([
                'orders_count' => (int) ($agg->orders_count ?? 0),
                'total_spent' => (float) ($agg->total_spent ?? 0),
                'first_order_at' => $agg->first_order_at,
                'last_order_at' => $agg->last_order_at,
                'name' => $latest->customer_name ?? 'Customer',
                'phone' => $latest->customer_phone ?? null,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('store_orders', function (Blueprint $table) {
            if (Schema::hasColumn('store_orders', 'store_customer_id')) {
                $table->dropConstrainedForeignId('store_customer_id');
            }
            if (Schema::hasColumn('store_orders', 'invoice_number')) {
                $table->dropColumn('invoice_number');
            }
        });

        Schema::dropIfExists('store_order_items');
        Schema::dropIfExists('store_customers');
    }
};
