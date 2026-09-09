<?php

return [
    // Merchant app URL for Paystack callback / return links.
    'app_url' => rtrim(env('STOREHAUSE_APP_URL', 'http://localhost:3000'), '/'),

    // Fallback daily AI allowance. Plans may override with their own `ai_daily_credits`.
    // Purchased credits stack on top of whichever daily allowance applies.
    'ai_daily_credits' => 5,

    // Plan + local free trial new merchants land on before they subscribe via Paystack.
    // StoreHause owns the no-card trial from merchant signup (`created_at` + trial_days).
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
            'plan_code' => env('PAYSTACK_PLAN_STARTER'),
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
            'plan_code' => env('PAYSTACK_PLAN_GROWTH'),
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
            'plan_code' => env('PAYSTACK_PLAN_SCALE'),
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

    'add_ons' => [
        'sms' => [
            [
                'id' => 'sms_500',
                'units' => 500,
                'price_ngn' => 3_000,
                'price_label' => 'NGN 3,000',
            ],
            [
                'id' => 'sms_1000',
                'units' => 1_000,
                'price_ngn' => 5_500,
                'price_label' => 'NGN 5,500',
            ],
            [
                'id' => 'sms_2500',
                'units' => 2_500,
                'price_ngn' => 12_000,
                'price_label' => 'NGN 12,000',
            ],
        ],
        'whatsapp' => [
            [
                'id' => 'wa_200',
                'units' => 200,
                'price_ngn' => 4_000,
                'price_label' => 'NGN 4,000',
            ],
            [
                'id' => 'wa_500',
                'units' => 500,
                'price_ngn' => 9_000,
                'price_label' => 'NGN 9,000',
            ],
            [
                'id' => 'wa_1000',
                'units' => 1_000,
                'price_ngn' => 16_000,
                'price_label' => 'NGN 16,000',
            ],
        ],
        'ai_credits' => [
            [
                'id' => 'ai_50',
                'credits' => 50,
                'price_ngn' => 2_000,
                'price_label' => 'NGN 2,000',
            ],
            [
                'id' => 'ai_200',
                'credits' => 200,
                'price_ngn' => 6_000,
                'price_label' => 'NGN 6,000',
            ],
            [
                'id' => 'ai_500',
                'credits' => 500,
                'price_ngn' => 12_000,
                'price_label' => 'NGN 12,000',
            ],
        ],
    ],
];
