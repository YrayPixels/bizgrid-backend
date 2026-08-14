<?php

use App\Agents\ShoppingShopperAgent;
use App\Models\Merchant;
use App\Models\ShopperSession;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createPublishedShopperStore(): Store
{
    $user = User::factory()->create();
    $merchant = Merchant::create([
        'owner_user_id' => $user->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals-shopper',
        'industry' => 'beauty_and_skincare',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'active',
        'activated_at' => now(),
    ]);

    $store = Store::create([
        'merchant_id' => $merchant->id,
        'name' => 'Glow Rituals',
        'slug' => 'glow-rituals-shopper',
        'status' => 'published',
        'primary_domain' => 'glow-rituals-shopper.example.test',
        'description' => 'Organic skincare.',
        'brand_color' => '#0E7C66',
        'published_json' => ['hero' => ['headline' => 'Welcome']],
        'published_at' => now(),
    ]);

    StoreProduct::create([
        'id' => (string) Str::uuid(),
        'store_id' => $store->id,
        'slug' => 'vitamin-c-serum',
        'name' => 'Vitamin C Serum',
        'description' => 'Brightening daily serum.',
        'price' => 8500,
        'currency' => 'NGN',
        'status' => 'active',
        'stock_quantity' => 12,
    ]);

    return $store;
}

it('creates a shopper session and restores the welcome on config', function () {
    $store = createPublishedShopperStore();

    $response = $this->getJson('/api/storehause/public/storefronts/glow-rituals-shopper/ai/config?session_id=visit-session-1');

    $response->assertOk()
        ->assertJsonPath('session_id', 'visit-session-1')
        ->assertJsonPath('messages.0.role', 'assistant');

    expect($response->json('messages.0.content'))->not->toBeEmpty()
        ->and(ShopperSession::query()->where('client_key', 'visit-session-1')->count())->toBe(1);
});

it('keeps shopper conversation history across turns with tool calling', function () {
    $store = createPublishedShopperStore();
    $this->getJson('/api/storehause/public/storefronts/glow-rituals-shopper/ai/config?session_id=visit-session-2')
        ->assertOk();

    $this->mock(ShoppingShopperAgent::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('systemPrompt')->andReturn('You are a personal shopper.');
        $mock->shouldReceive('tools')->andReturn([]);
        $mock->shouldReceive('complete')->andReturn(
            [
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_search_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_catalog',
                        'arguments' => json_encode(['query' => 'serum']),
                    ],
                ]],
            ],
            [
                'content' => 'I’d start with the Vitamin C Serum — it’s a brightening daily serum from this catalog at ₦8,500.',
                'tool_calls' => [],
            ],
        );
    });

    $shop = $this->postJson('/api/storehause/public/storefronts/glow-rituals-shopper/ai/shop', [
        'message' => 'I need a serum for glow',
        'session_id' => 'visit-session-2',
    ]);

    $shop->assertOk()
        ->assertJsonPath('session_id', 'visit-session-2');

    expect($shop->json('reply'))->toContain('Vitamin C Serum')
        ->and($shop->json('reply'))->not->toContain('I found 1 option')
        ->and($shop->json('recommendation.items.0.product.name'))->toBe('Vitamin C Serum');

    $replay = $this->getJson('/api/storehause/public/storefronts/glow-rituals-shopper/ai/config?session_id=visit-session-2');
    $replay->assertOk();

    $roles = array_column($replay->json('messages'), 'role');
    $contents = array_column($replay->json('messages'), 'content');

    expect($roles)->toContain('user')
        ->and($roles)->toContain('assistant')
        ->and($contents)->toContain('I need a serum for glow')
        ->and($replay->json('recommendation.items.0.product.name'))->toBe('Vitamin C Serum');
});
