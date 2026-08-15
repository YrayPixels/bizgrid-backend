<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI provider
    |--------------------------------------------------------------------------
    |
    | Used by the website builder and any agent without a feature override.
    | Supported: "openai", "deepseek", "gemini"
    | Can be overridden in platform admin settings.
    |
    */
    'provider' => env('AI_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Feature-level providers (shopper, marketing, vision)
    |--------------------------------------------------------------------------
    |
    | Defaults before anything is saved in platform admin → AI settings.
    | Admin preferences are stored in platform_settings and take precedence.
    | If the chosen provider has no API key, the builder provider is used
    | (OpenAI for vision when Gemini is missing).
    |
    */
    'features' => [
        'shopper' => env('AI_SHOPPER_PROVIDER', 'gemini'),
        'marketing' => env('AI_MARKETING_PROVIDER', 'gemini'),
        'vision' => env('AI_VISION_PROVIDER', 'gemini'),
    ],

    'agent_features' => [
        'shopping-shopper-agent' => 'shopper',
        'shopping-intent-agent' => 'shopper',
        'shopping-planner-agent' => 'shopper',
        'shopping-product-picker-agent' => 'shopper',
        'marketing-agent' => 'marketing',
    ],

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
            'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o'),
        ],
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY'),
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'chat_model' => env('DEEPSEEK_CHAT_MODEL', 'deepseek-v4-pro'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
            'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-3.6-flash'),
            'vision_model' => env('GEMINI_VISION_MODEL', 'gemini-3.6-flash'),
            // api_key = Google AI Studio prepaid. vertex = Cloud billing via the GCS service account.
            'auth' => env('GEMINI_AUTH', 'api_key'),
            'location' => env('GEMINI_LOCATION', 'global'),
        ],
    ],

    'models' => [
        'openai' => [
            'chat' => [
                ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'description' => 'Fast and affordable for everyday builder tasks'],
                ['id' => 'gpt-4o', 'label' => 'GPT-4o', 'description' => 'Strong general-purpose model'],
                ['id' => 'gpt-4.1', 'label' => 'GPT-4.1', 'description' => 'Latest flagship reasoning and coding'],
                ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 Mini', 'description' => 'Lower cost GPT-4.1 variant'],
                ['id' => 'o3-mini', 'label' => 'o3 Mini', 'description' => 'Reasoning-focused model'],
            ],
            'vision' => [
                ['id' => 'gpt-4o', 'label' => 'GPT-4o', 'description' => 'Best for product image analysis'],
                ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'description' => 'Lower cost vision'],
                ['id' => 'gpt-4.1', 'label' => 'GPT-4.1', 'description' => 'Higher quality vision analysis'],
                ['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 Mini', 'description' => 'Balanced vision cost and quality'],
            ],
        ],
        'deepseek' => [
            'chat' => [
                ['id' => 'deepseek-v4-pro', 'label' => 'DeepSeek V4 Pro', 'description' => 'Recommended production model'],
                ['id' => 'deepseek-chat', 'label' => 'DeepSeek Chat', 'description' => 'General conversation and edits'],
                ['id' => 'deepseek-reasoner', 'label' => 'DeepSeek Reasoner', 'description' => 'Stronger multi-step reasoning'],
                ['id' => 'deepseek-coder', 'label' => 'DeepSeek Coder', 'description' => 'Optimized for code generation'],
                ['id' => 'deepseek-v3.2', 'label' => 'DeepSeek V3.2', 'description' => 'Coding and tool use'],
                ['id' => 'deepseek-v3.2-speciale', 'label' => 'DeepSeek V3.2 Speciale', 'description' => 'High-compute variant'],
            ],
        ],
        'gemini' => [
            'chat' => [
                ['id' => 'gemini-3.6-flash', 'label' => 'Gemini 3.6 Flash', 'description' => 'Default for shopper, vision, and marketing'],
                ['id' => 'gemini-3.1-pro-preview', 'label' => 'Gemini 3.1 Pro', 'description' => 'Higher quality reasoning'],
                ['id' => 'gemini-3.1-flash-lite', 'label' => 'Gemini 3.1 Flash-Lite', 'description' => 'Lowest-cost Gemini'],
            ],
            'vision' => [
                ['id' => 'gemini-3.6-flash', 'label' => 'Gemini 3.6 Flash', 'description' => 'Product photo → name, price, description'],
                ['id' => 'gemini-3.1-pro-preview', 'label' => 'Gemini 3.1 Pro', 'description' => 'Higher quality image understanding'],
                ['id' => 'gemini-3.1-flash-lite', 'label' => 'Gemini 3.1 Flash-Lite', 'description' => 'Fastest, lowest-cost vision'],
            ],
        ],
    ],
];
