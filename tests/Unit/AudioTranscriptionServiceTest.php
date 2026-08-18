<?php

use App\Services\AudioTranscriptionService;
use App\Services\PlatformAiConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('transcribes audio with openai whisper', function () {
    config(['ai.providers.openai.api_key' => 'test-openai-key']);
    app(PlatformAiConfigService::class)->clearCache();

    Http::fake([
        'api.openai.com/*' => Http::response(['text' => 'add product'], 200),
    ]);

    $text = app(AudioTranscriptionService::class)->transcribe('fake-ogg-bytes', 'audio/ogg');

    expect($text)->toBe('add product');
});
