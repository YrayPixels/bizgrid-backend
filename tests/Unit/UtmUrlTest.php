<?php

use App\Support\UtmUrl;

it('merges utm params onto a clean url', function () {
    $url = UtmUrl::merge('https://glow.example.test/products/serum', [
        'utm_source' => 'facebook',
        'utm_medium' => 'social',
        'utm_campaign' => 'glow-market',
        'utm_content' => 'post_12',
    ]);

    expect($url)->toBe(
        'https://glow.example.test/products/serum?utm_source=facebook&utm_medium=social&utm_campaign=glow-market&utm_content=post_12'
    );
});

it('overwrites existing utm params without dropping unrelated query keys', function () {
    $url = UtmUrl::merge('https://glow.example.test/checkout?recover=ABC&utm_source=old', [
        'utm_source' => 'recovery',
        'utm_medium' => 'email',
        'utm_campaign' => 'glow-market',
        'utm_content' => 'recovery',
    ]);

    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect($query['recover'])->toBe('ABC');
    expect($query['utm_source'])->toBe('recovery');
    expect($query['utm_content'])->toBe('recovery');
});

it('builds social post attribution params from the provider', function () {
    expect(UtmUrl::forSocialPost('instagram', 9, 'Glow Market'))->toMatchArray([
        'utm_source' => 'instagram',
        'utm_medium' => 'social',
        'utm_campaign' => 'glow-market',
        'utm_content' => 'post_9',
    ]);
});
