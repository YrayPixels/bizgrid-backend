# OpenAI Realtime API Integration

This document details the OpenAI Realtime API integration in the HeySolana backend, which provides voice AI capabilities for the mobile application.

## Table of Contents

- [Overview](#overview)
- [Configuration](#configuration)
- [API Endpoint](#api-endpoint)
- [Implementation Details](#implementation-details)
- [Troubleshooting](#troubleshooting)
- [Best Practices](#best-practices)

## Overview

The HeySolana backend integrates with OpenAI's Realtime API to enable voice-based AI interactions in the mobile app. The backend generates temporary session tokens that the mobile app uses to establish WebSocket connections with OpenAI's Realtime API.

### Why This Architecture?

- **Security**: API keys are never exposed to the mobile client
- **Control**: Backend can customize AI behavior per request
- **Monitoring**: All token generation goes through our backend
- **Flexibility**: Easy to switch models or adjust configurations

## Configuration

### Step 1: Get OpenAI API Key

1. Sign up at [platform.openai.com](https://platform.openai.com)
2. Navigate to **API Keys** section
3. Click **Create new secret key**
4. Copy the key (starts with `sk-proj-...`)

### Step 2: Add to Environment

**Local Development (.env):**
```bash
OPENAI_API_KEY="sk-proj-your-key-here"
```

**Production:**
- Add the key to your production server's `.env` file
- Never commit API keys to version control

### Step 3: Config File

The API key is accessed via `config/openai.php`:

```php
<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
];
```

**Important:** Always use `config('openai.api_key')` in your code, never `env('OPENAI_API_KEY')`.

## API Endpoint

### POST `/api/open-token`

Generates an ephemeral OpenAI Realtime API session token.

#### Request

```bash
POST /api/open-token
Content-Type: application/json

{
  "prompt": "Optional transcription hint for better accuracy"
}
```

**Parameters:**
- `prompt` (optional, string): Context hint to improve audio transcription accuracy

#### Response

**Success (200):**
```json
{
  "client_secret": {
    "value": "sess_abc123...",
    "expires_at": 1709856000
  }
}
```

**Error (401):**
```json
{
  "error": {
    "message": "Incorrect API key provided...",
    "type": "invalid_request_error",
    "code": "invalid_api_key"
  }
}
```

**Error (500):**
```json
{
  "error": "cURL Error: Connection timeout"
}
```

#### Session Token Usage

The mobile app uses the returned `client_secret.value` to connect to OpenAI's Realtime WebSocket:

```javascript
const ws = new WebSocket(
  'wss://api.openai.com/v1/realtime?model=gpt-4o-mini-realtime-preview-2024-12-17',
  {
    headers: {
      'Authorization': `Bearer ${sessionToken}`,
      'OpenAI-Beta': 'realtime=v1'
    }
  }
);
```

## Implementation Details

### Controller: `OpenTokenController.php`

```php
public function generate(Request $request)
{
    $apiKey = config('openai.api_key'); // ✅ Correct
    // $apiKey = env('OPENAI_API_KEY'); // ❌ Wrong - returns null when config cached
    
    $url = "https://api.openai.com/v1/realtime/sessions";
    $likelyprompt = $request->input('prompt');
    
    $postData = [
        "model" => "gpt-4o-mini-realtime-preview-2024-12-17",
        "instructions" => "You are a helpful, witty, and friendly AI...",
        "voice" => "alloy",
        "input_audio_transcription" => [
            "model" => "gpt-4o-mini-transcribe",
            "prompt" => $likelyprompt . " use the prompt above to correctly transcribe the audio into text"
        ],
        "input_audio_noise_reduction" => [
            "type" => "far_field"
        ]
    ];
    
    // ... cURL request to OpenAI ...
}
```

### Configuration Options

#### Model
```php
"model" => "gpt-4o-mini-realtime-preview-2024-12-17"
```
- Fast, cost-effective realtime model
- Optimized for voice interactions

#### Voice
```php
"voice" => "alloy"
```
Available voices: `alloy`, `echo`, `fable`, `onyx`, `nova`, `shimmer`

#### Instructions
```php
"instructions" => "Custom system prompt..."
```
Defines the AI's personality and behavior

#### Audio Transcription
```php
"input_audio_transcription" => [
    "model" => "gpt-4o-mini-transcribe",
    "prompt" => "Context hint for better transcription"
]
```
- Enables automatic audio-to-text transcription
- `prompt` parameter helps with domain-specific terms

#### Noise Reduction
```php
"input_audio_noise_reduction" => [
    "type" => "far_field"
]
```
- `far_field`: Optimized for distant/noisy audio
- Improves transcription quality in real-world conditions

## Troubleshooting

### Issue: "Incorrect API key provided: ''"

**Cause:** The `OPENAI_API_KEY` is empty or not being read correctly.

**Solutions:**

1. **Check if key exists in production `.env`:**
   ```bash
   # SSH or use cPanel File Manager
   cat .env | grep OPENAI_API_KEY
   ```

2. **Verify you're using `config()` not `env()`:**
   ```php
   // ✅ Correct
   $apiKey = config('openai.api_key');
   
   // ❌ Wrong - returns null when config cached
   $apiKey = env('OPENAI_API_KEY');
   ```

3. **Clear config cache:**
   ```bash
   # Via endpoint
   curl "https://your-domain.com/maintenance/cache-clear?key={DEPLOY_KEY}"
   
   # Or via SSH
   php artisan config:clear
   php artisan config:cache
   ```

4. **Check config file exists:**
   Ensure `config/openai.php` exists with correct content

### Issue: Config Changes Not Taking Effect

**Cause:** Laravel caches configuration in production.

**Solution:**
```bash
php artisan config:clear  # Clear first
php artisan config:cache  # Then rebuild
```

**In your deployment workflow:** Ensure cache clearing runs AFTER config files are uploaded.

### Issue: Session Token Expired

**Cause:** OpenAI session tokens have a limited lifetime (typically 60 seconds).

**Solution:**
- Generate new tokens as needed
- Implement token refresh logic in mobile app
- Don't cache tokens

### Issue: Rate Limiting

**Cause:** Too many requests to OpenAI API.

**Solution:**
- Implement token caching (with TTL)
- Add rate limiting middleware
- Monitor OpenAI usage dashboard

## Best Practices

### 1. Environment Variables

**✅ DO:**
```php
// In config/openai.php
return [
    'api_key' => env('OPENAI_API_KEY'),
];

// In your code
$apiKey = config('openai.api_key');
```

**❌ DON'T:**
```php
// In your code (breaks with cached config)
$apiKey = env('OPENAI_API_KEY');
```

### 2. Error Handling

Always handle OpenAI API errors gracefully:

```php
if (curl_errno($ch)) {
    Log::error('OpenAI API Error', [
        'error' => curl_error($ch),
        'url' => $url
    ]);
    return response()->json([
        'error' => 'Failed to generate session token'
    ], 500);
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if ($httpCode !== 200) {
    Log::error('OpenAI API HTTP Error', [
        'status' => $httpCode,
        'response' => $response
    ]);
}
```

### 3. Security

- ✅ Keep API keys in `.env` files
- ✅ Never commit `.env` to version control
- ✅ Use separate API keys for dev/staging/production
- ✅ Rotate keys periodically
- ✅ Monitor usage in OpenAI dashboard
- ❌ Never expose keys to mobile clients
- ❌ Never hardcode keys in source code

### 4. Cost Management

- Monitor token usage in OpenAI dashboard
- Set usage limits and alerts
- Consider caching session tokens (with proper TTL)
- Use `gpt-4o-mini` for cost-effective operation

### 5. Cache Management

**Development:**
```bash
# Don't cache configs in development
APP_ENV=local
```

**Production:**
```bash
# Always cache in production for performance
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**After changes:**
```bash
php artisan config:clear
php artisan config:cache
```

## Testing

### Test Endpoint Locally

```bash
curl -X POST http://localhost:8000/api/open-token \
  -H "Content-Type: application/json" \
  -d '{"prompt": "test prompt"}'
```

### Expected Response

```json
{
  "client_secret": {
    "value": "sess_...",
    "expires_at": 1709856000
  }
}
```

### Debug Endpoint

Use the debug endpoint to verify configuration (requires deploy key):

```bash
curl "http://localhost:8000/debug-env?key=your-deploy-key"
```

**Expected output when working:**
```json
{
  "OPENAI_API_KEY_exists": true,
  "OPENAI_API_KEY_empty": false,
  "OPENAI_API_KEY_length": 164,
  "first_10_chars": "sk-proj-vq",
  "config_cached": true
}
```

**Note:** The debug endpoint is protected by the `DEPLOY_KEY` for security.

## Resources

- [OpenAI Realtime API Documentation](https://platform.openai.com/docs/guides/realtime)
- [Laravel Configuration Documentation](https://laravel.com/docs/configuration)
- [OpenAI API Keys Management](https://platform.openai.com/api-keys)

## Support

For issues or questions about the OpenAI integration, contact the development team or check the main [README.md](README.md).
