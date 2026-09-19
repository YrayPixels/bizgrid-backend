<?php

use App\Jobs\SendAdminEmailMessageJob;
use App\Models\AdminEmailMessage;
use App\Models\AdminEmailThread;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

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
        ->assertJsonPath('data.message_count', 1)
        ->assertJsonPath('message', 'Email queued.');

    $threadId = $compose->json('data.id');
    expect($threadId)->not->toBeNull();
    expect(AdminEmailMessage::query()->where('thread_id', $threadId)->value('status'))->toBe('sent');

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
        ->assertJsonPath('data.message_count', 3)
        ->assertJsonPath('message', 'Reply queued.');

    $list = $this->actingAs($admin)->getJson('/api/admin/email-threads?status=open');
    $list->assertOk()
        ->assertJsonPath('data.0.id', $threadId)
        ->assertJsonStructure(['mailbox_configured']);

    $archive = $this->actingAs($admin)->patchJson("/api/admin/email-threads/{$threadId}", [
        'status' => 'archived',
    ]);
    $archive->assertOk()->assertJsonPath('data.status', 'archived');
});

it('queues one delayed job per compose recipient instead of blasting smtp', function () {
    Queue::fake();
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'support@bizgrid.test',
        'storehause.admin_mail.send_delay_seconds' => 20,
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $compose = $this->actingAs($admin)->postJson('/api/admin/email-threads', [
        'to' => ['one@example.com', 'two@example.com', 'three@example.com'],
        'subject' => 'Launch update',
        'body_text' => 'Here is the update.',
    ]);

    $compose->assertCreated()
        ->assertJsonPath('data.message_count', 3)
        ->assertJsonPath('message', 'Queued 3 emails.');

    $messages = AdminEmailMessage::query()->orderBy('id')->get();
    expect($messages)->toHaveCount(3);
    expect($messages->pluck('status')->unique()->all())->toBe(['queued']);
    expect($messages->map(fn ($row) => collect($row->to_emails)->pluck('email')->all())->all())->toBe([
        ['one@example.com'],
        ['two@example.com'],
        ['three@example.com'],
    ]);

    $pushed = collect();
    Queue::assertPushed(SendAdminEmailMessageJob::class, function (SendAdminEmailMessageJob $job) use ($pushed) {
        $pushed->push($job);

        return true;
    });
    expect($pushed)->toHaveCount(3);
    expect($pushed->pluck('messageId')->map(fn ($id) => (int) $id)->all())->toBe(
        $messages->pluck('id')->map(fn ($id) => (int) $id)->all()
    );

    $delaySeconds = function (SendAdminEmailMessageJob $job): int {
        if ($job->delay instanceof DateTimeInterface) {
            return (int) round($job->delay->getTimestamp() - time());
        }

        return (int) ($job->delay ?? 0);
    };

    expect($delaySeconds($pushed[0]))->toBe(0);
    expect($delaySeconds($pushed[1]))->toBeGreaterThanOrEqual(19)->toBeLessThanOrEqual(21);
    expect($delaySeconds($pushed[2]))->toBeGreaterThanOrEqual(39)->toBeLessThanOrEqual(41);

    Mail::assertNothingSent();
});

it('keeps cc and bcc on a single queued compose copy', function () {
    Queue::fake();
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'support@bizgrid.test',
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $this->actingAs($admin)->postJson('/api/admin/email-threads', [
        'to' => ['ada@example.com'],
        'cc' => ['cc@example.com'],
        'bcc' => ['hidden@example.com'],
        'subject' => 'Launch update',
        'body_text' => 'Here is the update.',
    ])->assertCreated()
        ->assertJsonPath('data.message_count', 1)
        ->assertJsonPath('data.messages.0.cc_emails.0.email', 'cc@example.com')
        ->assertJsonPath('data.messages.0.bcc_emails.0.email', 'hidden@example.com');

    $message = AdminEmailMessage::query()->first();
    expect($message?->to_emails)->toBe([['email' => 'ada@example.com', 'name' => null]]);
    expect(collect($message?->cc_emails)->pluck('email')->all())->toBe(['cc@example.com']);
    expect(collect($message?->bcc_emails)->pluck('email')->all())->toBe(['hidden@example.com']);
    Queue::assertPushed(SendAdminEmailMessageJob::class, 1);
    Mail::assertNothingSent();
});

it('queues bcc-only recipients one at a time', function () {
    Queue::fake();
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'support@bizgrid.test',
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $this->actingAs($admin)->postJson('/api/admin/email-threads', [
        'bcc' => ['one@example.com', 'two@example.com'],
        'subject' => 'Silent update',
        'body_text' => 'Bcc only.',
    ])->assertCreated()
        ->assertJsonPath('data.message_count', 2);

    $messages = AdminEmailMessage::query()->orderBy('id')->get();
    expect($messages)->toHaveCount(2);
    expect($messages->every(fn ($row) => $row->to_emails === [] || $row->to_emails === null))->toBeTrue();
    expect($messages->map(fn ($row) => collect($row->bcc_emails)->pluck('email')->all())->all())->toBe([
        ['one@example.com'],
        ['two@example.com'],
    ]);
    Queue::assertPushed(SendAdminEmailMessageJob::class, 2);
});

it('renders html templates and substitutes variables per recipient', function () {
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'support@bizgrid.test',
        'storehause.brand_name' => 'Bizgrid',
        'storehause.admin_mail.send_delay_seconds' => 0,
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);
    $owner = User::factory()->create([
        'name' => 'Ada Okafor',
        'email' => 'ada@glow.test',
    ]);
    $merchant = Merchant::create([
        'owner_user_id' => $owner->id,
        'business_name' => 'Glow Rituals',
        'slug' => 'glow-rituals-mail',
        'industry' => 'fashion',
        'status' => 'active',
        'subscription_plan' => 'starter',
        'subscription_status' => 'trialing',
    ]);

    $this->actingAs($admin)->postJson('/api/admin/email-threads', [
        'merchant_ids' => [$merchant->id],
        'subject' => 'Hello {{first_name}}',
        'body_html' => '<h1>Hi {{name}}</h1><p>{{business_name}} — {{coupon}}</p>',
        'variables' => ['coupon' => 'SAVE20'],
    ])->assertCreated();

    $message = AdminEmailMessage::query()->first();
    expect($message?->subject)->toBe('Hello Ada');
    expect($message?->body_html)
        ->toContain('Hi Ada Okafor')
        ->toContain('Glow Rituals')
        ->toContain('SAVE20')
        ->not->toContain('{{name}}')
        ->toContain('You are receiving this email from Bizgrid');
});

it('sends a full html document without wrapping it in the platform layout', function () {
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'support@bizgrid.test',
        'storehause.brand_name' => 'Bizgrid',
        'storehause.admin_mail.send_delay_seconds' => 0,
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $this->actingAs($admin)->postJson('/api/admin/email-threads', [
        'to' => 'customer@example.com',
        'subject' => 'Hello {{name}}',
        'body_html' => '<!DOCTYPE html><html><body><h1>Hi {{name}}</h1></body></html>',
    ])->assertCreated();

    $message = AdminEmailMessage::query()->first();
    expect($message?->subject)->toBe('Hello customer');
    expect($message?->body_html)
        ->toContain('<!DOCTYPE html>')
        ->toContain('Hi customer')
        ->not->toContain('You are receiving this email from');
});

it('sends a queued compose message to a single recipient', function () {
    config([
        'mail.default' => 'array',
        'mail.from.address' => 'support@bizgrid.test',
        'storehause.admin_mail.send_delay_seconds' => 0,
    ]);

    $admin = User::factory()->create([
        'is_admin' => true,
        'admin_role' => 'super_admin',
    ]);

    $this->actingAs($admin)->postJson('/api/admin/email-threads', [
        'to' => ['ada@example.com', 'bola@example.com'],
        'subject' => 'Hello',
        'body_text' => 'One at a time.',
    ])->assertCreated();

    $messages = AdminEmailMessage::query()->orderBy('id')->get();
    expect($messages)->toHaveCount(2);
    expect($messages->pluck('status')->unique()->all())->toBe(['sent']);
    expect($messages[0]->to_emails)->toBe([['email' => 'ada@example.com', 'name' => null]]);
    expect($messages[1]->to_emails)->toBe([['email' => 'bola@example.com', 'name' => null]]);
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
