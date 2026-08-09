# Bizgrid Backend API

Laravel backend for **Bizgrid** — AI-powered storefronts for small businesses.

Product architecture and build backlog: see `../storehause/docs/arch.md`.

## Features

- Merchant registration and authentication (Sanctum)
- Store and storefront management
- AI storefront generation (OpenAI)
- Storefront builder chat sessions
- Public storefront API (products, orders, visit tracking)
- Platform admin API (merchants, storefront templates)

## Requirements

- PHP 8.2+
- MySQL 5.7+
- Composer
- OpenAI API key (for AI storefront generation)

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

For organizer one-click demo login, set `STOREHAUSE_DEMO_LOGIN=true` before seeding (or run `php artisan db:seed --class=DemoMerchantSeeder`), then open the merchant app at `/demo`.

## Environment

```env
APP_NAME=Bizgrid
DB_DATABASE=storehause

OPENAI_API_KEY=your_openai_api_key_here

# Optional: judge/demo merchant
# STOREHAUSE_DEMO_LOGIN=true
# STOREHAUSE_DEMO_EMAIL=demo@bizgrid.shop
```

## API Overview

All routes are prefixed with `/api`.

### Bizgrid (merchants)

- `POST /storehause/auth/register` — Register merchant
- `POST /storehause/auth/login` — Login
- `GET /storehause/public/storefronts/{slug}` — Public storefront
- `POST /storehause/public/storefronts/{slug}/orders` — Place order
- Authenticated: dashboard, stores, products, AI builder, orders

### Platform admin

- `POST /login-admin`, `POST /verify-admin` — Admin auth
- `GET /admin/merchants` — Merchant management
- `GET /admin/storefront-templates` — Template management

## Deployment

See `RAILWAY.md` for Railway deployment notes.

Maintenance endpoints (protected by `DEPLOY_KEY`):

- `POST /maintenance/migrate?key=...`
- `POST /maintenance/cache-clear?key=...`
- `POST /maintenance/mail-test?key=...&to=you@example.com` — sends a raw SMTP test and returns the active mail config / error
- `POST /maintenance/seed-demo?key=...` — create/reset the organizer demo merchant (`DemoMerchantSeeder`)

### cPanel / organizer demo

1. In production `.env` set:

```env
STOREHAUSE_DEMO_LOGIN=true
STOREHAUSE_DEMO_EMAIL=demo@bizgrid.shop
DEPLOY_KEY=your_secure_deploy_key_here
```

2. Clear config so the flag is live (same as your normal post-deploy step):

```bash
curl -X POST "https://YOUR-API-DOMAIN/maintenance/cache-clear?key=YOUR_DEPLOY_KEY"
```

3. Seed (or reset) the demo account:

```bash
curl -X POST "https://YOUR-API-DOMAIN/maintenance/seed-demo?key=YOUR_DEPLOY_KEY"
```

4. On the merchant frontend, set `NEXT_PUBLIC_ENABLE_DEMO_LOGIN=true`, redeploy, then open `/demo`.

Re-run step 3 any time the shared demo store gets messy.
