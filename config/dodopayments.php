<?php

return [
    'api_key' => env('DODO_PAYMENTS_API_KEY'),
    'webhook_secret' => env('DODO_PAYMENTS_WEBHOOK_SECRET'),
    'environment' => env('DODO_PAYMENTS_ENVIRONMENT', 'test_mode'),
    'app_url' => rtrim(env('STOREHAUSE_APP_URL', 'http://localhost:3000'), '/'),

    // Fallback daily AI allowance. Plans may override with their own `ai_daily_credits`.
    // Purchased credits stack on top of whichever daily allowance applies.
    'ai_daily_credits' => 5,

    // Plan + local free trial new merchants land on before they subscribe via Dodo.
    // StoreHause owns the no-card 14-day trial (`subscription_renews_at`).
    // Configure Dodo product trials to 0 days so checkout does not stack a second trial.
    'default_plan' => 'starter',
    'trial_days' => 14,

    // Percentage added to every online order at checkout, kept by the platform.
    // Applies on all plans (trial and paid). Offline/POS is not fee-charged.
    'transaction_fee_percent' => 2.5,

    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'price_monthly_ngn' => 5_000,
            'price_label' => 'NGN 5,000',
            'description' => 'Launch your first store and start selling with essential limits.',
            'product_id' => env('DODO_PRODUCT_STARTER'),
            'caps' => [
                'monthly_processing_ngn' => null,
                'max_stores' => 1,
                'max_customers' => null,
                'custom_domains' => false,
                'max_custom_domains' => 0,
                'offline_payments' => true,
            ],
            'included_monthly' => [
                'sms_units' => 100,
                'whatsapp_units' => 50,
            ],
            'features' => [
                '14-day free trial',
                '2.5% service fee per online order',
                'Unlimited payment processing',
                '1 storefront',
                'Unlimited customers',
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
                'monthly_processing_ngn' => null,
                'max_stores' => 3,
                'max_customers' => null,
                'custom_domains' => true,
                'max_custom_domains' => 1,
                'offline_payments' => true,
            ],
            'included_monthly' => [
                'sms_units' => 300,
                'whatsapp_units' => 150,
            ],
            'features' => [
                '14-day free trial',
                '2.5% service fee per online order',
                'Unlimited payment processing',
                'Up to 3 storefronts',
                'Unlimited customers',
                '1 custom domain',
                '300 SMS + 150 WhatsApp units/month',
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
                'monthly_processing_ngn' => null,
                'max_stores' => 10,
                'max_customers' => null,
                'custom_domains' => true,
                'max_custom_domains' => 5,
                'offline_payments' => true,
            ],
            'included_monthly' => [
                'sms_units' => 750,
                'whatsapp_units' => 350,
            ],
            'features' => [
                '14-day free trial',
                '2.5% service fee per online order',
                'Unlimited payment processing',
                'Up to 10 storefronts',
                'Unlimited customers',
                'Up to 5 custom domains',
                '750 SMS + 350 WhatsApp units/month',
                '5 AI queries per day',
            ],
        ],
    ],

    // Credit entitlement IDs — purchased pack products grant into these on payment.
    'credits' => [
        'sms' => env('DODO_CREDIT_SMS'),
        'whatsapp' => env('DODO_CREDIT_WHATSAPP'),
        'ai' => env('DODO_CREDIT_AI'),
    ],

    'add_ons' => [
        'sms' => [
            [
                'id' => 'sms_500',
                'units' => 500,
                'price_label' => 'NGN 3,000',
                'product_id' => env('DODO_PRODUCT_SMS_500'),
            ],
            [
                'id' => 'sms_1000',
                'units' => 1_000,
                'price_label' => 'NGN 5,500',
                'product_id' => env('DODO_PRODUCT_SMS_1000'),
            ],
            [
                'id' => 'sms_2500',
                'units' => 2_500,
                'price_label' => 'NGN 12,000',
                'product_id' => env('DODO_PRODUCT_SMS_2500'),
            ],
        ],
        'whatsapp' => [
            [
                'id' => 'wa_200',
                'units' => 200,
                'price_label' => 'NGN 4,000',
                'product_id' => env('DODO_PRODUCT_WHATSAPP_200'),
            ],
            [
                'id' => 'wa_500',
                'units' => 500,
                'price_label' => 'NGN 9,000',
                'product_id' => env('DODO_PRODUCT_WHATSAPP_500'),
            ],
            [
                'id' => 'wa_1000',
                'units' => 1_000,
                'price_label' => 'NGN 16,000',
                'product_id' => env('DODO_PRODUCT_WHATSAPP_1000'),
            ],
        ],
        'ai_credits' => [
            [
                'id' => 'ai_50',
                'credits' => 50,
                'price_label' => 'NGN 2,000',
                'product_id' => env('DODO_PRODUCT_AI_50'),
            ],
            [
                'id' => 'ai_200',
                'credits' => 200,
                'price_label' => 'NGN 6,000',
                'product_id' => env('DODO_PRODUCT_AI_200'),
            ],
            [
                'id' => 'ai_500',
                'credits' => 500,
                'price_label' => 'NGN 12,000',
                'product_id' => env('DODO_PRODUCT_AI_500'),
            ],
        ],
    ],
];
