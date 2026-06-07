#!/usr/bin/env sh
set -e



# Laravel 11+: schedule:work runs the scheduler in a loop (every minute)
php artisan schedule:work
