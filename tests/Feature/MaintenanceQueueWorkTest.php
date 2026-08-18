<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.deploy_key' => 'test-deploy-key',
        'queue.default' => 'database',
    ]);
});

it('rejects queue-work without a valid deploy key', function () {
    $this->get('/maintenance/queue-work')->assertForbidden();
    $this->post('/maintenance/queue-work')->assertForbidden();
});

it('processes database jobs when pinged with the deploy key', function () {
    Cache::put('queue-work-marker', 0);

    dispatch(function () {
        Cache::increment('queue-work-marker');
    })->onConnection('database');

    expect(DB::table('jobs')->count())->toBe(1);

    $this->get('/maintenance/queue-work?key=test-deploy-key&max_time=8&max_jobs=5')
        ->assertOk()
        ->assertJsonPath('message', 'Queue processed')
        ->assertJsonPath('processed', 1)
        ->assertJsonPath('pending_after', 0);

    expect(Cache::get('queue-work-marker'))->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});
