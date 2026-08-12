# Bizgrid Backend API

Laravel API for **Bizgrid** — AI-powered storefronts for small businesses.

This repository is the **REST API**. The merchant app and platform admin live in sibling repos.

**Production API:** `https://business.yraytestings.com.ng` · **Merchant app:** [bizgrid.shop](https://www.bizgrid.shop)

Product architecture: see [`storehause/docs/arch.md`](https://github.com/YrayPixels/bigrid-frontend/blob/main/docs/arch.md) in the frontend repo.

## Related repositories

| Local folder | GitHub | Role | Stack |
|--------------|--------|------|-------|
| `storehause/` | [YrayPixels/bigrid-frontend](https://github.com/YrayPixels/bigrid-frontend) | Merchant dashboard, AI website builder, hosted storefronts | Next.js 15, React 19, Tailwind |
| `storehausebackend/` (this repo) | [YrayPixels/bizgrid-backend](https://github.com/YrayPixels/bizgrid-backend) | REST API, auth, AI agents, orders, billing | Laravel 11, Sanctum, MySQL |
| `storehouseadmin/` | [YrayPixels/storehouseadmin](https://github.com/YrayPixels/storehouseadmin) | Internal platform ops (merchants, templates, agent logs) | Vite, React 18 |

```bash
git clone https://github.com/YrayPixels/bizgrid-backend.git storehausebackend
git clone https://github.com/YrayPixels/bigrid-frontend.git storehause
git clone https://github.com/YrayPixels/storehouseadmin.git storehouseadmin   # optional
```

## Features

- Merchant registration, email verification, Google OAuth, Sanctum sessions
- Store and storefront management (draft, publish, images)
- AI storefront generation and builder chat (OpenAI or DeepSeek)
- Shopping agents (intent, planner, product picker) and vision analysis
- Public storefront API (catalog, orders, visit tracking, AI shopper)
- Paystack checkout and Dodo subscription billing
- Platform admin API (merchants, templates, agent execution logs, health)
- Optional WhatsApp / Facebook / TikTok marketing hooks and PerfectCorp try-on

## Requirements

- PHP 8.2+
- MySQL 5.7+ / 8.x
- Composer 2.x
- OpenAI **or** DeepSeek API key (for AI storefront generation and shopping agents)

Optional: Redis (`docker compose up -d` in this repo), queue worker, Paystack / Dodo / Google OAuth keys.

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Minimum `.env` for a local demo:

```env
APP_NAME=Bizgrid
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=storehause
DB_USERNAME=root
DB_PASSWORD=

OPENAI_API_KEY=your_openai_api_key_here
AI_PROVIDER=openai

STOREHAUSE_APP_URL=http://localhost:3000
STOREHAUSE_PLATFORM_DOMAIN=localhost
STOREHAUSE_BRAND_NAME=Bizgrid
STOREHAUSE_ADMIN_APP_URL=http://localhost:5173
STOREHAUSE_ADMIN_EMAIL=admin@storehause.local
STOREHAUSE_ADMIN_PASSWORD=choose-a-strong-password
```

Create the database, then:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS storehause;"
php artisan migrate
php artisan db:seed
php artisan serve
```

API: **http://localhost:8000** (routes under `/api`).

Queue workers (recommended for AI / async jobs):

```bash
php artisan queue:listen --tries=1
```

For organizer one-click demo login, set `STOREHAUSE_DEMO_LOGIN=true` before seeding (or run `php artisan db:seed --class=DemoMerchantSeeder`), then open the merchant app at `/demo`.

Tests: `php artisan test` (Pest).

## Environment

See [`.env.example`](./.env.example) for the full list. Common groups:

| Area | Variables |
|------|-----------|
| AI | `AI_PROVIDER` (`openai` or `deepseek`), `OPENAI_API_KEY`, `DEEPSEEK_API_KEY` |
| Platform | `STOREHAUSE_APP_URL`, `STOREHAUSE_PLATFORM_DOMAIN`, `STOREHAUSE_BRAND_NAME` |
| Admin seed | `STOREHAUSE_ADMIN_EMAIL`, `STOREHAUSE_ADMIN_PASSWORD` |
| Demo merchant | `STOREHAUSE_DEMO_LOGIN`, `STOREHAUSE_DEMO_EMAIL` |
| Payments | `PAYSTACK_*`, `DODO_PAYMENTS_*` |
| Google sign-in | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` |
| Deploy | `DEPLOY_KEY` (protects `/maintenance/*`) |

AI provider can also be changed in the platform admin **AI settings** page.

## API overview

All routes are prefixed with `/api`.

### Merchants

- `POST /storehause/auth/register` — Register
- `POST /storehause/auth/login` — Login
- `POST /storehause/auth/demo-login` — One-click demo merchant (requires `STOREHAUSE_DEMO_LOGIN`)
- `GET /storehause/public/storefronts/{slug}` — Public storefront
- `POST /storehause/public/storefronts/{slug}/orders` — Place order
- Authenticated: dashboard, stores, products, AI builder, orders, shopping agents

### Platform admin

- `POST /login-admin`, `POST /verify-admin` — Admin auth
- `GET /admin/merchants` — Merchant management
- `GET /admin/storefront-templates` — Template management
- Agent logs, health, billing, and AI config under `/admin/*`

## Deployment

Production deploys from `main` via GitHub Actions: Composer install, incremental FTP upload to cPanel, then `POST /maintenance/migrate` and `POST /maintenance/cache-clear`.

Maintenance endpoints (protected by `DEPLOY_KEY`):

- `POST /maintenance/migrate?key=...`
- `POST /maintenance/cache-clear?key=...`
- `POST /maintenance/mail-test?key=...&to=you@example.com` — SMTP test; returns active mail config / error
- `POST /maintenance/seed-demo?key=...` — create/reset the organizer demo merchant (`DemoMerchantSeeder`)

### Organizer demo on production

1. In production `.env` set:

```env
STOREHAUSE_DEMO_LOGIN=true
STOREHAUSE_DEMO_EMAIL=demo@bizgrid.shop
DEPLOY_KEY=your_secure_deploy_key_here
```

2. Clear config so the flag is live:

```bash
curl -X POST "https://YOUR-API-DOMAIN/maintenance/cache-clear?key=YOUR_DEPLOY_KEY"
```

3. Seed (or reset) the demo account:

```bash
curl -X POST "https://YOUR-API-DOMAIN/maintenance/seed-demo?key=YOUR_DEPLOY_KEY"
```

4. On the merchant frontend, set `NEXT_PUBLIC_ENABLE_DEMO_LOGIN=true`, redeploy, then open `/demo`.

Re-run step 3 any time the shared demo store gets messy.

Optional Railway notes (not the current production path) are in [`RAILWAY.md`](./RAILWAY.md).

## License

Private / source-available for review unless otherwise stated in the repository license file.
