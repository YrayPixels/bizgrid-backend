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
