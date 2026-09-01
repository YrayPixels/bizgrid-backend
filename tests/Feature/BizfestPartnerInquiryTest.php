<?php

declare(strict_types=1);

use App\Models\BizfestPartnerInquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('accepts a bizfest sponsor inquiry with sponsorship interest', function () {
    $response = $this->postJson('/api/storehause/public/bizfest/partner-inquiries', [
        'inquiry_type' => 'sponsor',
        'company_name' => 'Example Bank',
        'contact_name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'phone' => '+2348000000000',
        'interests' => ['sponsorship'],
        'tier_interest' => 'Gold sponsor',
        'message' => 'Interested in conference branding.',
        'programme' => 'bizfest-1',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', 1);

    $this->assertDatabaseHas('bizfest_partner_inquiries', [
        'inquiry_type' => 'sponsor',
        'company_name' => 'Example Bank',
        'email' => 'jane@example.com',
        'tier_interest' => 'Gold sponsor',
        'status' => 'new',
    ]);

    $inquiry = BizfestPartnerInquiry::query()->first();
    expect($inquiry->interests)->toBe(['sponsorship']);
});

it('accepts a bizfest sponsor inquiry with expo booth and space', function () {
    $response = $this->postJson('/api/storehause/public/bizfest/partner-inquiries', [
        'inquiry_type' => 'sponsor',
        'company_name' => 'Expo Brand Ltd',
        'contact_name' => 'Sam Okon',
        'email' => 'sam@expobrand.com',
        'phone' => '+2348222222222',
        'interests' => ['expo_booth', 'exhibition_space'],
        'booth_package' => 'Premium booth',
        'booth_quantity' => 2,
        'space_package' => 'Demo zone',
    ]);

    $response->assertCreated();

    $inquiry = BizfestPartnerInquiry::query()->first();
    expect($inquiry)->not->toBeNull()
        ->and($inquiry->inquiry_type)->toBe('sponsor')
        ->and($inquiry->interests)->toBe(['expo_booth', 'exhibition_space'])
        ->and($inquiry->booth_package)->toBe('Premium booth')
        ->and($inquiry->booth_quantity)->toBe(2)
        ->and($inquiry->space_package)->toBe('Demo zone')
        ->and($inquiry->tier_interest)->toBeNull();
});

it('accepts a bizfest partnership inquiry without tier', function () {
    $response = $this->postJson('/api/storehause/public/bizfest/partner-inquiries', [
        'inquiry_type' => 'partner',
        'company_name' => 'SME Association',
        'contact_name' => 'John Smith',
        'email' => 'john@sme.org',
        'phone' => '+2348111111111',
    ]);

    $response->assertCreated();

    $inquiry = BizfestPartnerInquiry::query()->first();
    expect($inquiry)->not->toBeNull()
        ->and($inquiry->inquiry_type)->toBe('partner')
        ->and($inquiry->tier_interest)->toBeNull()
        ->and($inquiry->interests)->toBeNull();
});

it('rejects sponsor inquiry without interests', function () {
    $this->postJson('/api/storehause/public/bizfest/partner-inquiries', [
        'inquiry_type' => 'sponsor',
        'company_name' => 'Test Co',
        'contact_name' => 'Test',
        'email' => 'test@example.com',
        'phone' => '+2348000000000',
    ])->assertStatus(422);
});

it('rejects invalid bizfest partner inquiry type', function () {
    $this->postJson('/api/storehause/public/bizfest/partner-inquiries', [
        'inquiry_type' => 'invalid',
        'company_name' => 'Test Co',
        'contact_name' => 'Test',
        'email' => 'test@example.com',
        'phone' => '+2348000000000',
    ])->assertStatus(422);
});
