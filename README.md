# HeySolana Backend API

Laravel-based backend API for the HeySolana wallet application, providing user management, transaction handling, AI integration, and third-party service integrations (Jumia, Crossmint).

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Environment Configuration](#environment-configuration)
- [API Endpoints](#api-endpoints)
- [OpenAI Integration](#openai-integration)
- [Deployment](#deployment)
- [Maintenance](#maintenance)
- [Documentation](#documentation)

## Features

- User management and address book
- Transaction tracking
- Admin dashboard with authentication
- Jumia integration for e-commerce orders
- Crossmint (Amazon) integration
- Exchange rate management (NGN/USD)
- Email notifications and waitlist management
- OpenAI Realtime API integration for voice AI
- Automated deployment via GitHub Actions

## Requirements

- PHP 8.2+
- MySQL 5.7+
- Composer
- OpenAI API key

## Installation

### Local Development

1. Clone the repository:
```bash
git clone <repository-url>
cd heysolanabackend
```

2. Install dependencies:
```bash
composer install
```

3. Copy environment file:
```bash
cp .env.example .env
```

4. Generate application key:
```bash
php artisan key:generate
```

5. Configure your `.env` file (see [Environment Configuration](#environment-configuration))

6. Run migrations:
```bash
php artisan migrate
```

7. Start the development server:
```bash
php artisan serve
```

## Environment Configuration

### Required Environment Variables

```bash
# Application
APP_NAME=HeySolana
APP_ENV=local
APP_KEY=base64:your-key-here
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=heysol
DB_USERNAME=root
DB_PASSWORD=

# OpenAI Configuration (Required for voice AI features)
OPENAI_API_KEY="sk-proj-your-openai-api-key-here"

# Mail Configuration
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=465
MAIL_USERNAME=your-email@domain.com
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS=noreply@domain.com
MAIL_FROM_NAME="${APP_NAME}"

# Cache & Session
CACHE_STORE=database
SESSION_DRIVER=database

# Deployment
DEPLOY_KEY=your-deploy-key-here
```

### Getting an OpenAI API Key

1. Sign up at [OpenAI Platform](https://platform.openai.com)
2. Navigate to API Keys section
3. Create a new API key
4. Add it to your `.env` file as `OPENAI_API_KEY`

**Important:** Never commit your `.env` file to version control!

## API Endpoints

### User Management
- `POST /api/create-user` - Create new user
- `GET /api/fetch-users` - Get all users
- `GET /api/fetch-user/{id}` - Get specific user

### Transactions
- `POST /api/add-tx` - Add transaction
- `GET /api/get-tx/{id}` - Get transaction details

### Admin (Protected by Sanctum)
- `POST /api/login-admin` - Admin login
- `POST /api/verify-admin` - Verify admin token
- `POST /api/create-admin` - Create admin account
- `POST /api/fetch-admins` - List all admins
- `POST /api/delete-admin` - Delete admin

### Jumia Integration
- `GET /api/jumia/wallet/delivery-addresses` - Get delivery addresses
- `POST /api/jumia/wallet/delivery-addresses` - Create delivery address
- `POST /api/jumia/wallet/orders` - Place Jumia order

### Crossmint (Amazon) Integration
- `POST /api/crossmint/wallet/orders/create` - Create order
- `POST /api/crossmint/wallet/orders` - Record order

### OpenAI Realtime API
- `POST /api/open-token` - Generate OpenAI Realtime session token

### Analytics
- `GET /api/user-analytics` - Get user distribution analytics

### Maintenance (Protected by DEPLOY_KEY)
- `GET /maintenance/cache-clear?key={DEPLOY_KEY}` - Clear and rebuild caches
- `GET /maintenance/migrate?key={DEPLOY_KEY}` - Run database migrations

### Debug (Protected by DEPLOY_KEY)
- `GET /debug-env?key={DEPLOY_KEY}` - Check environment configuration status

## OpenAI Integration

### Overview

The backend provides OpenAI Realtime API integration for voice AI features in the HeySolana mobile app.

### Endpoint: `/api/open-token`

Generates an OpenAI Realtime session token for voice interactions.

**Request:**
```bash
POST /api/open-token
Content-Type: application/json

{
  "prompt": "Optional transcription hint"
}
```

**Response:**
```json
{
  "client_secret": {
    "value": "sess_...",
    "expires_at": 1234567890
  }
}
```

### Configuration

The OpenAI integration uses the `config/openai.php` configuration file:

```php
<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
];
```

**Important:** Always use `config('openai.api_key')` instead of `env('OPENAI_API_KEY')` in your code to ensure compatibility with cached configurations in production.

### Implementation Details

- Model: `gpt-4o-mini-realtime-preview-2024-12-17`
- Voice: `alloy`
- Features:
  - Real-time audio input/output
  - Audio transcription with custom prompts
  - Far-field noise reduction
  - Function calling support

## Deployment

### Automated Deployment (GitHub Actions)

The project uses GitHub Actions for automated deployment to production via FTP.

**Workflow:** `.github/workflows/deploy.yml`

**On push to `main` branch:**
1. Checks out code
2. Sets up PHP 8.2
3. Installs Composer dependencies
4. Uploads changed files via FTP
5. Clears and rebuilds Laravel caches

### Manual Deployment

1. Upload files to production server via FTP
2. Ensure `.env` file exists on server with correct values
3. Run cache clear:
```bash
curl "https://your-domain.com/maintenance/cache-clear?key={DEPLOY_KEY}"
```

### Production Environment Setup

1. Create `.env` file on production server (don't upload from local)
2. Set `APP_ENV=production`
3. Set `APP_DEBUG=false`
4. Configure production database credentials
5. Add `OPENAI_API_KEY` to production `.env`
6. Add `DEPLOY_KEY` for maintenance endpoints

## Maintenance

### Cache Management

Laravel uses config caching for performance in production. When config is cached, `env()` calls outside config files return `null`.

**Best Practices:**
- Always use `config()` helper in application code
- Only use `env()` in config files
- Clear cache after environment changes

**Clear all caches:**
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

**Rebuild caches:**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Common Issues

#### OpenAI API Key Not Working
- Ensure `OPENAI_API_KEY` is in production `.env` file
- Clear config cache: visit `/maintenance/cache-clear?key={DEPLOY_KEY}`
- Verify using `config('openai.api_key')` not `env('OPENAI_API_KEY')`

#### Cache Not Clearing
- Check cache-clear route order (clear before rebuild)
- Verify file permissions on `bootstrap/cache/`
- Check `DEPLOY_KEY` is correct

### Protected Endpoints

All maintenance and debug endpoints are protected by the `DEPLOY_KEY` for security:

**Usage:**
```bash
# Cache clear
curl "https://your-domain.com/maintenance/cache-clear?key={DEPLOY_KEY}"

# Run migrations
curl "https://your-domain.com/maintenance/migrate?key={DEPLOY_KEY}"

# Debug environment (check config status)
curl "https://your-domain.com/debug-env?key={DEPLOY_KEY}"
```

**Important:** These endpoints use `config('app.deploy_key')` instead of `env('DEPLOY_KEY')` to work correctly with cached configurations in production.

## Documentation

Additional documentation available:

- [Jumia API Integration](JUMIA_API_README.md)
- [Railway Deployment](RAILWAY.md)
- [Product Details](productdetails.md)

## Security

- Never commit `.env` files
- Keep `OPENAI_API_KEY` secret
- Protect admin endpoints with Sanctum authentication
- Use `DEPLOY_KEY` for maintenance endpoints
- Enable HTTPS in production

## License

Proprietary - HeySolana

## Support

For issues or questions, contact the development team.
