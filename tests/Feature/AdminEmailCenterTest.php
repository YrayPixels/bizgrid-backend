<?php

use App\Models\AdminEmailMessage;
use App\Models\AdminEmailThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

it('lets admins compose reply and list email threads', function () {
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'support@bizgrid.test',
        'mail.from.name' => 'Bizgrid Support',
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $compose = $this->actingAs($admin)->postJson('/api/admin/email-threads', [
        'to' => 'customer@example.com',
        'subject' => 'Welcome',
        'body_text' => 'Thanks for signing up.',
    ]);

    $compose->assertCreated()
        ->assertJsonPath('data.subject', 'Welcome')
        ->assertJsonPath('data.message_count', 1);

    $threadId = $compose->json('data.id');
    expect($threadId)->not->toBeNull();

    // Simulate an inbound reply so reply() has a recipient.
    AdminEmailMessage::query()->create([
        'thread_id' => $threadId,
        'direction' => 'inbound',
        'from_email' => 'merchant@example.com',
        'from_name' => 'Merchant',
        'to_emails' => [['email' => 'support@bizgrid.test', 'name' => null]],
        'subject' => 'Re: Welcome',
        'body_text' => 'Got it, thanks.',
        'message_id' => '<inbound-1@example.com>',
        'status' => 'received',
    ]);
    AdminEmailThread::query()->whereKey($threadId)->update([
        'message_count' => 2,
        'last_direction' => 'inbound',
        'last_message_at' => now(),
    ]);

    $reply = $this->actingAs($admin)->postJson("/api/admin/email-threads/{$threadId}/reply", [
        'body_text' => 'Happy to help.',
    ]);

    $reply->assertOk()
        ->assertJsonPath('data.message_count', 3);

    $list = $this->actingAs($admin)->getJson('/api/admin/email-threads?status=open');
    $list->assertOk()
        ->assertJsonPath('data.0.id', $threadId)
        ->assertJsonStructure(['mailbox_configured']);

    $archive = $this->actingAs($admin)->patchJson("/api/admin/email-threads/{$threadId}", [
        'status' => 'archived',
    ]);
    $archive->assertOk()->assertJsonPath('data.status', 'archived');
});

it('requires imap extension awareness when polling without mailboxes', function () {
    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/admin/email-mailbox/poll');

    $response->assertOk()
        ->assertJsonPath('data.polled', 0)
        ->assertJsonPath('data.imported', 0);
});
