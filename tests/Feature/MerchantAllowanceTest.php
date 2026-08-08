<?php

declare(strict_types=1);

use App\Models\Merchant;
use App\Models\User;
use App\Services\MerchantUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function allowanceMerchant(array $attributes = []): Merchant
{
    $user = User::factory()->create();

    return Merchant::create(array_merge([
        'owner_user_id' => $user->id,
        'business_name' => 'Allowance Co',
        'slug' => 'allowance-co-'.uniqid(),
        'industry' => 'other',
        'status' => 'active',
        'subscription_plan' => 'growth',
        'subscription_status' => 'active',
    ], $attributes));
}

it('grants the plan allowance to a merchant that has never had one', function () {
    $merchant = allowanceMerchant();

    expect((int) $merchant->sms_included_remaining)->toBe(0);

    app(MerchantUsageService::class)->ensureMonthlyPeriod($merchant);
    $merchant->refresh();

    expect((int) $merchant->sms_included_remaining)->toBe(300)
        ->and((int) $merchant->whatsapp_included_remaining)->toBe(150);
});

it('refills included units when a new month starts', function () {
    $merchant = allowanceMerchant();
    $usage = app(MerchantUsageService::class);

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();

    // Burn the month's allowance.
    $merchant->sms_included_remaining = 0;
    $merchant->whatsapp_included_remaining = 0;
    $merchant->save();

    Carbon::setTestNow(now()->addMonthNoOverflow()->startOfMonth()->addDay());

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();

    expect((int) $merchant->sms_included_remaining)->toBe(300)
        ->and((int) $merchant->whatsapp_included_remaining)->toBe(150);

    Carbon::setTestNow();
});

it('does not regrant exhausted units when the plan page is reloaded', function () {
    $merchant = allowanceMerchant();
    $usage = app(MerchantUsageService::class);

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();

    $merchant->sms_included_remaining = 0;
    $merchant->whatsapp_included_remaining = 0;
    $merchant->save();

    // Reloading the plan page used to refill exhausted units on demand, because
    // formatSubscription() regranted whenever both included balances hit zero.
    $owner = User::find($merchant->owner_user_id);
    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/storehause/billing/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.usage.sms.included_remaining', 0)
            ->assertJsonPath('subscription.usage.whatsapp.included_remaining', 0);
    }

    $merchant->refresh();

    expect((int) $merchant->sms_included_remaining)->toBe(0)
        ->and((int) $merchant->whatsapp_included_remaining)->toBe(0);
});

it('keeps purchased top-up balances across a refill', function () {
    $merchant = allowanceMerchant();
    $usage = app(MerchantUsageService::class);

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();

    $merchant->sms_included_remaining = 10;
    $merchant->sms_purchased_balance = 500;
    $merchant->whatsapp_purchased_balance = 200;
    $merchant->save();

    Carbon::setTestNow(now()->addMonthNoOverflow()->startOfMonth()->addDay());

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();

    // Included units reset to the plan allowance; paid-for balances roll over.
    expect((int) $merchant->sms_included_remaining)->toBe(300)
        ->and((int) $merchant->sms_purchased_balance)->toBe(500)
        ->and((int) $merchant->whatsapp_purchased_balance)->toBe(200);

    Carbon::setTestNow();
});

it('refills before reporting whether a whatsapp send is allowed', function () {
    $merchant = allowanceMerchant();
    $usage = app(MerchantUsageService::class);

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();

    $merchant->whatsapp_included_remaining = 0;
    $merchant->save();

    expect($usage->canSendWhatsapp($merchant))->toBeFalse();

    Carbon::setTestNow(now()->addMonthNoOverflow()->startOfMonth()->addDay());

    expect($usage->canSendWhatsapp($merchant))->toBeTrue();

    Carbon::setTestNow();
});

it('burns included sms units before purchased ones', function () {
    $merchant = allowanceMerchant();
    $usage = app(MerchantUsageService::class);

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();

    $merchant->sms_included_remaining = 2;
    $merchant->sms_purchased_balance = 5;
    $merchant->save();

    $usage->consumeSmsUnit($merchant);
    $usage->consumeSmsUnit($merchant);
    $merchant->refresh();

    expect((int) $merchant->sms_included_remaining)->toBe(0)
        ->and((int) $merchant->sms_purchased_balance)->toBe(5);

    // Included exhausted, so the next send comes out of the purchased balance.
    $usage->consumeSmsUnit($merchant);
    $merchant->refresh();

    expect((int) $merchant->sms_purchased_balance)->toBe(4)
        ->and($usage->canSendSms($merchant))->toBeTrue();
});

it('reports no sms available once every balance is spent', function () {
    $merchant = allowanceMerchant();
    $usage = app(MerchantUsageService::class);

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();

    $merchant->sms_included_remaining = 0;
    $merchant->sms_purchased_balance = 0;
    $merchant->save();

    expect($usage->canSendSms($merchant))->toBeFalse();

    // Consuming with nothing left must not drive a balance negative.
    $usage->consumeSmsUnit($merchant);
    $merchant->refresh();

    expect((int) $merchant->sms_included_remaining)->toBe(0)
        ->and((int) $merchant->sms_purchased_balance)->toBe(0);
});

it('accepts the statuses and plans the system actually writes from admin', function () {
    $merchant = allowanceMerchant(['subscription_plan' => 'growth']);
    $admin = User::factory()->create(['is_admin' => true, 'admin_role' => 'super_admin']);

    foreach (Merchant::SUBSCRIPTION_STATUSES as $status) {
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/merchants/{$merchant->id}/billing", [
                'subscription_status' => $status,
            ])
            ->assertOk();
    }

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/admin/merchants/{$merchant->id}/billing", [
            'subscription_plan' => 'free',
        ])
        ->assertOk();

    expect($merchant->fresh()->subscription_plan)->toBe('free');

    // The spellings the old admin dropdown sent are no longer accepted.
    foreach (['trial', 'past_due'] as $legacy) {
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/merchants/{$merchant->id}/billing", [
                'subscription_status' => $legacy,
            ])
            ->assertStatus(422);
    }
});

it('gives the free plan no messaging allowance', function () {
    $merchant = allowanceMerchant(['subscription_plan' => 'free']);
    $usage = app(MerchantUsageService::class);

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();

    expect((int) $merchant->sms_included_remaining)->toBe(0)
        ->and((int) $merchant->whatsapp_included_remaining)->toBe(0)
        ->and($usage->canSendWhatsapp($merchant))->toBeFalse();
});

it('allows sms when only a dodo purchased balance remains', function () {
    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.environment' => 'test_mode',
        'dodopayments.credits.sms' => 'cde_sms_test',
    ]);

    Http::fake([
        'https://test.dodopayments.com/credit-entitlements/cde_sms_test/balances/cus_sms_1' => Http::response([
            'id' => 'bal_1',
            'customer_id' => 'cus_sms_1',
            'credit_entitlement_id' => 'cde_sms_test',
            'balance' => '12',
            'overage' => '0',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], 200),
    ]);

    $merchant = allowanceMerchant(['dodo_customer_id' => 'cus_sms_1']);
    $usage = app(MerchantUsageService::class);

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();
    $merchant->sms_included_remaining = 0;
    $merchant->sms_purchased_balance = 0;
    $merchant->save();

    expect($usage->canSendSms($merchant))->toBeTrue();
});

it('debits dodo after local sms balances are exhausted', function () {
    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.environment' => 'test_mode',
        'dodopayments.credits.sms' => 'cde_sms_test',
    ]);

    Http::fake([
        'https://test.dodopayments.com/credit-entitlements/cde_sms_test/balances/cus_sms_2' => Http::response([
            'balance' => '3',
            'overage' => '0',
            'id' => 'bal_2',
            'customer_id' => 'cus_sms_2',
            'credit_entitlement_id' => 'cde_sms_test',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], 200),
        'https://test.dodopayments.com/credit-entitlements/cde_sms_test/balances/cus_sms_2/ledger-entries' => Http::response([
            'id' => 'led_1',
            'customer_id' => 'cus_sms_2',
            'credit_entitlement_id' => 'cde_sms_test',
            'entry_type' => 'debit',
            'amount' => '1',
            'is_credit' => false,
            'balance_before' => '3',
            'balance_after' => '2',
            'overage_before' => '0',
            'overage_after' => '0',
            'created_at' => now()->toIso8601String(),
        ], 201),
    ]);

    $merchant = allowanceMerchant(['dodo_customer_id' => 'cus_sms_2']);
    $usage = app(MerchantUsageService::class);

    $usage->ensureMonthlyPeriod($merchant);
    $merchant->refresh();
    $merchant->sms_included_remaining = 0;
    $merchant->sms_purchased_balance = 0;
    $merchant->save();

    $usage->consumeSmsUnit($merchant, 'sms:test-debit-1');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_ends_with($request->url(), '/ledger-entries')
            && ($body['entry_type'] ?? null) === 'debit'
            && ($body['amount'] ?? null) === '1'
            && ($body['idempotency_key'] ?? null) === 'sms:test-debit-1';
    });
});

it('includes dodo balances in purchased messaging totals', function () {
    config([
        'dodopayments.api_key' => 'test_api_key',
        'dodopayments.environment' => 'test_mode',
        'dodopayments.credits.sms' => 'cde_sms_test',
        'dodopayments.credits.whatsapp' => 'cde_wa_test',
        'dodopayments.credits.ai' => 'cde_ai_test',
    ]);

    Http::fake([
        'https://test.dodopayments.com/credit-entitlements/cde_sms_test/balances/cus_fmt_1' => Http::response([
            'balance' => '40',
            'overage' => '0',
            'id' => 'bal_sms',
            'customer_id' => 'cus_fmt_1',
            'credit_entitlement_id' => 'cde_sms_test',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], 200),
        'https://test.dodopayments.com/credit-entitlements/cde_wa_test/balances/cus_fmt_1' => Http::response([
            'balance' => '7',
            'overage' => '0',
            'id' => 'bal_wa',
            'customer_id' => 'cus_fmt_1',
            'credit_entitlement_id' => 'cde_wa_test',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], 200),
        'https://test.dodopayments.com/credit-entitlements/cde_ai_test/balances/cus_fmt_1' => Http::response([
            'balance' => '15',
            'overage' => '0',
            'id' => 'bal_ai',
            'customer_id' => 'cus_fmt_1',
            'credit_entitlement_id' => 'cde_ai_test',
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ], 200),
    ]);

    $merchant = allowanceMerchant([
        'dodo_customer_id' => 'cus_fmt_1',
        'sms_purchased_balance' => 5,
        'whatsapp_purchased_balance' => 2,
        'ai_purchased_credits' => 3,
    ]);
    $usage = app(MerchantUsageService::class);
    $formatted = $usage->formatUsage($merchant);

    expect($formatted['sms']['purchased_balance'])->toBe(45)
        ->and($formatted['whatsapp']['purchased_balance'])->toBe(9)
        ->and($formatted['ai']['purchased_remaining'])->toBe(18);
});
