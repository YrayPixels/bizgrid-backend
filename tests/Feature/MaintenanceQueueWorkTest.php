<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    $this->get('/maintenance/queue-work?key=test-deploy-key&once=1&max_time=8&max_jobs=5')
        ->assertOk()
        ->assertJsonPath('message', 'Queue processed')
        ->assertJsonPath('processed', 1)
        ->assertJsonPath('pending_after', 0)
        ->assertJsonPath('once', true);

    expect(Cache::get('queue-work-marker'))->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('polls the queue for the cron window instead of exiting when empty', function () {
    Artisan::shouldReceive('call')
        ->once()
        ->withArgs(function (string $command, array $params): bool {
            return $command === 'queue:work'
                && ! array_key_exists('--stop-when-empty', $params)
                && ($params['--sleep'] ?? null) === 2
                && ($params['--max-time'] ?? null) === 26
                && ($params['--timeout'] ?? null) === 24
                && ($params['--tries'] ?? null) === 1;
        })
        ->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('');

    $this->get('/maintenance/queue-work?key=test-deploy-key')
        ->assertOk()
        ->assertJsonPath('message', 'Queue processed')
        ->assertJsonPath('sleep', 2)
        ->assertJsonPath('max_time', 26)
        ->assertJsonPath('once', false);
});
