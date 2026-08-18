<?php

use App\Models\WhatsAppMerchantMessage;
use App\Models\WhatsAppMerchantSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedWhatsAppPlatformConfig();
});

it('lists merchant whatsapp messages for admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $session = WhatsAppMerchantSession::query()->create([
        'phone' => '2348011111111',
        'state' => WhatsAppMerchantSession::STATE_IDLE,
    ]);

    WhatsAppMerchantMessage::query()->create([
        'whatsapp_merchant_session_id' => $session->id,
        'phone' => $session->phone,
        'direction' => WhatsAppMerchantMessage::DIRECTION_INBOUND,
        'message_type' => 'text',
        'body' => 'hi',
        'provider_message_id' => 'wamid.inbound.test',
    ]);

    WhatsAppMerchantMessage::query()->create([
        'whatsapp_merchant_session_id' => $session->id,
        'phone' => $session->phone,
        'direction' => WhatsAppMerchantMessage::DIRECTION_OUTBOUND,
        'message_type' => 'text',
        'body' => 'Welcome to Bizgrid!',
    ]);

    $this->actingAs($admin)
        ->getJson('/api/admin/whatsapp-messages')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.direction', 'outbound')
        ->assertJsonPath('data.1.body', 'hi');
});

it('filters merchant whatsapp messages by direction', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $session = WhatsAppMerchantSession::query()->create([
        'phone' => '2348022222222',
        'state' => WhatsAppMerchantSession::STATE_IDLE,
    ]);

    WhatsAppMerchantMessage::query()->create([
        'whatsapp_merchant_session_id' => $session->id,
        'phone' => $session->phone,
        'direction' => WhatsAppMerchantMessage::DIRECTION_INBOUND,
        'message_type' => 'text',
        'body' => 'orders',
        'provider_message_id' => 'wamid.inbound.orders',
    ]);

    WhatsAppMerchantMessage::query()->create([
        'whatsapp_merchant_session_id' => $session->id,
        'phone' => $session->phone,
        'direction' => WhatsAppMerchantMessage::DIRECTION_OUTBOUND,
        'message_type' => 'text',
        'body' => 'Latest orders:',
    ]);

    $this->actingAs($admin)
        ->getJson('/api/admin/whatsapp-messages?direction=inbound')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.body', 'orders');
});
