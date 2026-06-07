# Deploying heysolanabackend on Railway

Railway auto-detects this Laravel app and runs it with **PHP-FPM and Nginx**. Use either **CLI** or **GitHub** to deploy.

---

## Quick deploy (single web service)

1. **Create a project** at [railway.com/new](https://railway.com/new).
2. **Deploy from GitHub**  
   - Connect the repo.  
   - Add variables (see [Variables](#variables) below).  
   - Click **Deploy**.
3. **Generate a domain**  
   - Service → **Settings** → **Networking** → **Generate Domain**.

Optional: in the service **Build** section set **Custom Build Command** to:

```bash
npm run build
```

So Vite assets are built. Then in **Deploy** set **Pre-Deploy Command** to:

```bash
chmod +x ./railway/init-app.sh && sh ./railway/init-app.sh
```

This runs migrations and caches config/routes/views before each deploy.

---

## Variables

Set these in the service **Variables** (or **Raw Editor**).

| Variable | Example / note |
|----------|-----------------|
| `APP_KEY` | From `php artisan key:generate --show` (required) |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | Your Railway domain, e.g. `https://xxx.up.railway.app` |
| `LOG_CHANNEL` | `stderr` (so logs show in Railway) |
| `LOG_STDERR_FORMATTER` | `\Monolog\Formatter\JsonFormatter` (optional, for structured logs) |
| `DB_CONNECTION` | `mysql` |
| `DB_URL` | `${{MySQL.DATABASE_URL}}` (reference your MySQL service) |
| `QUEUE_CONNECTION` | `database` (if you use queues) |

Add any other env vars your app needs (e.g. APIs, `SESSION_DOMAIN`).

---

## Database (MySQL)

- In the same project, add a **MySQL** service (**New** → **Database** → **Add MySQL**).
- In your **app service** variables, set:
  - `DB_CONNECTION=mysql`
  - `DB_URL=${{MySQL.DATABASE_URL}}`  
  Replace `MySQL` with your MySQL service name if you renamed it (e.g. `MySql` or your custom name).

---

## Optional: worker and cron (separate services)

To run queue workers and the Laravel scheduler as separate services:

1. **Worker service**  
   - Same repo.  
   - **Deploy** → **Custom Start Command**:
   ```bash
   chmod +x ./railway/run-worker.sh && sh ./railway/run-worker.sh
   ```
   - Same variables as the app (including `DB_URL`, `QUEUE_CONNECTION=database`).

2. **Cron service**  
   - Same repo.  
   - **Deploy** → **Custom Start Command**:
   ```bash
   chmod +x ./railway/run-cron.sh && sh ./railway/run-cron.sh
   ```
   - Same variables as the app.

Only the **app service** needs a public domain. Worker and cron do not.

---

## Deploy from CLI

```bash
# Install Railway CLI, then:
cd /path/to/heysolanabackend
railway init
railway up
```

Add variables in the Railway dashboard, then trigger a redeploy.

---

## Files added for Railway

- `railway/init-app.sh` – migrations + config/route/view cache (Pre-Deploy).
- `railway/run-worker.sh` – `php artisan queue:work` (worker service start).
- `railway/run-cron.sh` – `php artisan schedule:work` (cron service start).

No Dockerfile is required; Railway uses Nixpacks to detect and build the Laravel app.
