<?php

use App\Services\StorefrontAiAgentService;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)
    ->in('Unit');
    
    
/*



|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function mockStorefrontAiAgent(callable $configure): void
{
    $mock = Mockery::mock(StorefrontAiAgentService::class);
    $mock->shouldReceive('available')->andReturn(true);
    $configure($mock);

    app()->instance(StorefrontAiAgentService::class, $mock);
}

function glowRitualsProfile(): array
{
    return [
        'business_name' => 'Glow Rituals',
        'description' => 'Organic skincare for busy professionals.',
        'industry' => 'beauty_and_skincare',
        'brand_color' => '#0E7C66',
        'tone' => ['premium', 'natural'],
    ];
}

function builderSessionWithDraft(App\Models\User $user, App\Models\Store $store, array $overrides = []): App\Models\StorefrontBuilderSession
{
    $builderService = app(App\Services\StorefrontBuilderService::class);
    $storefront = $builderService->synthesizeStorefront($store->fresh('merchant'));
    $store->draft_json = $storefront;
    $store->save();

    return App\Models\StorefrontBuilderSession::create(array_merge([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'status' => 'review_ready',
        'business_profile' => glowRitualsProfile(),
        'selected_template_id' => $store->storefront_template_id,
        'storefront_snapshot' => $storefront,
    ], $overrides));
}
