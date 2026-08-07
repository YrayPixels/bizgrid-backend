<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('storehause:release-unpaid-orders --hours=24')->hourly();

// Safety net for subscription webhooks that never landed. Overlap guard because a
// slow Dodo response would otherwise stack runs on top of each other.
Schedule::command('storehause:reconcile-subscriptions')->hourly()->withoutOverlapping();

// Scheduled posts are only as punctual as this tick, so run it every five
// minutes. Overlap guard: a slow Graph call must not double-publish.
Schedule::command('storehause:publish-scheduled-posts')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Engagement numbers and token health. Hourly is plenty — Meta's own insights
// lag by minutes anyway, and this keeps the API call budget modest.
Schedule::command('storehause:sync-marketing-insights')
    ->hourly()
    ->withoutOverlapping();
