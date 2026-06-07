# Changelog

All notable changes to the HeySolana Backend will be documented in this file.

## [Unreleased]

### Fixed - 2026-03-15

#### OpenAI API Key Issue in Production

**Problem:**
- `/api/open-token` endpoint was returning "Incorrect API key provided: ''" error in production
- OpenAI API key was present in production `.env` file but not being read correctly

**Root Cause:**
Laravel's config caching system disables `env()` calls outside of config files for performance and security. When using `env('OPENAI_API_KEY')` directly in controllers/routes with cached config, it always returns `null`.

**Solution:**
1. Created `config/openai.php` to properly expose the API key through Laravel's config system
2. Updated `OpenTokenController.php` to use `config('openai.api_key')` instead of `env('OPENAI_API_KEY')`
3. Updated `routes/apiv2.php` Whisper transcription endpoint to use `config('openai.api_key')`
4. Fixed cache clearing route order in `routes/web.php` to clear before rebuilding
5. Added `deploy_key` to `config/app.php` and updated all maintenance routes to use `config('app.deploy_key')`
6. Protected `/debug-env` endpoint with deploy key authentication

**Files Changed:**
- `config/openai.php` - Created new config file
- `config/app.php` - Added deploy_key configuration
- `app/Http/Controllers/OpenTokenController.php` - Changed line 13 to use config()
- `routes/apiv2.php` - Changed line 116 to use config()
- `routes/web.php` - Fixed cache-clear route order and all routes now use config() instead of env()

**Best Practice:**
Always use `config('key')` in application code, never `env('KEY')`. Only use `env()` within config files.

### Added - 2026-03-15

#### Documentation

**Files Added:**
- `README.md` - Comprehensive project documentation
- `OPENAI_INTEGRATION.md` - Detailed OpenAI integration guide
- `CHANGELOG.md` - This file

**Files Updated:**
- `.env.example` - Added `OPENAI_API_KEY` and `DEPLOY_KEY` placeholders

#### Debug Endpoint

Added `/debug-env?key={DEPLOY_KEY}` endpoint for troubleshooting configuration issues. Protected by deploy key for security.

### Technical Details

#### Why This Happened

Laravel's `config:cache` command:
1. Reads all config files
2. Compiles them into a single cached file
3. Disables `env()` function outside config files for performance

This is standard behavior in Laravel production environments and is documented in the [Laravel Configuration Documentation](https://laravel.com/docs/configuration#configuration-caching).

#### How to Prevent Similar Issues

1. **Never use `env()` outside config files**
   ```php
   // ❌ Wrong (breaks with cached config)
   $apiKey = env('OPENAI_API_KEY');
   
   // ✅ Correct
   $apiKey = config('openai.api_key');
   ```

2. **Always create config files for new environment variables**
   ```php
   // config/myservice.php
   return [
       'api_key' => env('MYSERVICE_API_KEY'),
   ];
   ```

3. **Clear config cache after environment changes**
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```

#### Testing in Production-like Environment

To test config caching locally:
```bash
# Enable config caching
php artisan config:cache

# Test your endpoints
curl http://localhost:8000/api/open-token

# Disable caching for development
php artisan config:clear
```

### Deployment Notes

When deploying to production:
1. Ensure all environment variables are in production `.env` file
2. Run `/maintenance/cache-clear?key={DEPLOY_KEY}` after deployment
3. Verify with `/debug-env` endpoint (then remove it)

## [Previous Versions]

_Add version history as needed_
