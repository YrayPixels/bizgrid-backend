#!/usr/bin/env sh
set -e

php artisan queue:work --tries=3
