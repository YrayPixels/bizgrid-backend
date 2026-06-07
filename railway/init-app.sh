#!/usr/bin/env sh
set -e

# Run migrations
php artisan migrate --force

# Cache config for production
php artisan config:cache
php artisan route:cache

php artisan view:cache
