<?php

return [
    'api_key' => env('OPENAI_API_KEY'),
    'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
    'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o'),
];
