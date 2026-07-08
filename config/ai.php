<?php

return [
  /*
    |--------------------------------------------------------------------------
    | Default AI provider
    |--------------------------------------------------------------------------
    |
    | Supported: "openai", "deepseek"
    | Can be overridden in platform admin settings.
    |
    */
    'provider' => env('AI_PROVIDER', 'openai'),

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
    ],
];
