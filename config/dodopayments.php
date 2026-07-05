<?php

return [
    'api_key' => env('DODO_PAYMENTS_API_KEY'),
    'webhook_secret' => env('DODO_PAYMENTS_WEBHOOK_SECRET'),
    'environment' => env('DODO_PAYMENTS_ENVIRONMENT', 'test_mode'),
    'app_url' => rtrim(env('STOREHAUSE_APP_URL', 'http://localhost:3000'), '/'),

    // All plans include 5 free AI queries per day; purchased credits stack on top.
    'ai_daily_credits' => 5,

    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'price_monthly_ngn' => 5_000,
            'price_label' => 'NGN 5,000',
            'description' => 'Launch your first store and start selling with essential limits.',
            'product_id' => env('DODO_PRODUCT_STARTER'),
            'caps' => [
                'monthly_processing_ngn' => 1_000_000,
                'max_stores' => 1,
                'max_customers' => 500,
                'custom_domains' => false,
                'max_custom_domains' => 0,
            ],
            'included_monthly' => [
                'sms_units' => 100,
                'whatsapp_units' => 50,
            ],
            'features' => [
                'Up to NGN 1M monthly processing',
                '1 storefront',
                'Up to 500 customers',
                '100 SMS + 50 WhatsApp units/month',
                '5 AI queries per day',
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            'price_monthly_ngn' => 15_000,
            'price_label' => 'NGN 15,000',
            'description' => 'For growing brands selling across channels with higher volume.',
            'product_id' => env('DODO_PRODUCT_GROWTH'),
            'caps' => [
                'monthly_processing_ngn' => 10_000_000,
                'max_stores' => 3,
                'max_customers' => 5_000,
                'custom_domains' => true,
                'max_custom_domains' => 1,
            ],
            'included_monthly' => [
                'sms_units' => 500,
                'whatsapp_units' => 300,
            ],
            'features' => [
                'Up to NGN 10M monthly processing',
                'Up to 3 storefronts',
                'Up to 5,000 customers',
                '1 custom domain',
                '500 SMS + 300 WhatsApp units/month',
                '5 AI queries per day',
            ],
        ],
        'scale' => [
            'name' => 'Scale',
            'price_monthly_ngn' => 30_000,
            'price_label' => 'NGN 30,000',
            'description' => 'For teams with high order volume and multi-store operations.',
            'product_id' => env('DODO_PRODUCT_SCALE'),
            'caps' => [
                'monthly_processing_ngn' => 50_000_000,
                'max_stores' => 10,
                'max_customers' => null,
                'custom_domains' => true,
                'max_custom_domains' => 5,
            ],
            'included_monthly' => [
                'sms_units' => 2_000,
                'whatsapp_units' => 1_500,
            ],
            'features' => [
                'Up to NGN 50M monthly processing',
                'Up to 10 storefronts',
                'Unlimited customers',
                'Up to 5 custom domains',
                '2,000 SMS + 1,500 WhatsApp units/month',
                '5 AI queries per day',
            ],
        ],
    ],

    'add_ons' => [
        'sms' => [
            [
                'id' => 'sms_500',
                'units' => 500,
                'price_label' => 'NGN 3,000',
                'product_id' => env('DODO_ADDON_SMS_500'),
            ],
            [
                'id' => 'sms_1000',
                'units' => 1_000,
                'price_label' => 'NGN 5,500',
                'product_id' => env('DODO_ADDON_SMS_1000'),
            ],
            [
                'id' => 'sms_2500',
                'units' => 2_500,
                'price_label' => 'NGN 12,000',
                'product_id' => env('DODO_ADDON_SMS_2500'),
            ],
        ],
        'whatsapp' => [
            [
                'id' => 'wa_200',
                'units' => 200,
                'price_label' => 'NGN 4,000',
                'product_id' => env('DODO_ADDON_WHATSAPP_200'),
            ],
            [
                'id' => 'wa_500',
                'units' => 500,
                'price_label' => 'NGN 9,000',
                'product_id' => env('DODO_ADDON_WHATSAPP_500'),
            ],
            [
                'id' => 'wa_1000',
                'units' => 1_000,
                'price_label' => 'NGN 16,000',
                'product_id' => env('DODO_ADDON_WHATSAPP_1000'),
            ],
        ],
        'ai_credits' => [
            [
                'id' => 'ai_50',
                'credits' => 50,
                'price_label' => 'NGN 2,000',
                'product_id' => env('DODO_ADDON_AI_50'),
            ],
            [
                'id' => 'ai_200',
                'credits' => 200,
                'price_label' => 'NGN 6,000',
                'product_id' => env('DODO_ADDON_AI_200'),
            ],
            [
                'id' => 'ai_500',
                'credits' => 500,
                'price_label' => 'NGN 12,000',
                'product_id' => env('DODO_ADDON_AI_500'),
            ],
        ],
    ],
];
