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

## Environment

```env
APP_NAME=Bizgrid
DB_DATABASE=storehause

OPENAI_API_KEY=your_openai_api_key_here
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

- `GET /maintenance/migrate?key=...`
- `GET /maintenance/cache-clear?key=...`
