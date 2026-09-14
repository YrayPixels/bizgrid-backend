<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Models\AdminEmailThread;
use App\Services\AdminAuditService;
use App\Services\AdminEmailSendService;
use App\Services\AdminMailboxImapService;
use App\Services\PlatformMailConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminEmailCenterController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly PlatformMailConfigService $mailConfig,
        private readonly AdminMailboxImapService $imap,
        private readonly AdminEmailSendService $sender,
        private readonly AdminAuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString() ?: 'open';
        $search = trim($request->string('search')->toString());
        $perPage = min(50, max(1, (int) $request->input('per_page', 20)));

        $query = AdminEmailThread::query()
            ->with(['messages' => fn ($q) => $q->latest('id')->limit(1)])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if (in_array($status, ['open', 'archived'], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', '%'.$search.'%')
                    ->orWhere('subject_normalized', 'like', '%'.strtolower($search).'%')
                    ->orWhere('participants', 'like', '%'.$search.'%');
            });
        }

        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn (AdminEmailThread $thread) => $this->serializeThread($thread))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'mailbox_configured' => $this->mailConfig->hasImapMailboxConfigured(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $thread = AdminEmailThread::query()
            ->with(['messages' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->serializeThread($thread, withMessages: true),
            'mailbox_configured' => $this->mailConfig->hasImapMailboxConfigured(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:open,archived',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $thread = AdminEmailThread::query()->findOrFail($id);
        $thread->update(['status' => $validator->validated()['status']]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeThread($thread->fresh()),
            'message' => 'Thread updated.',
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'body_text' => 'required|string|max:20000',
            'body_html' => 'nullable|string|max:50000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $thread = AdminEmailThread::query()->findOrFail($id);

        try {
            $message = $this->sender->reply($thread, $validator->validated(), $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send reply.',
                'error' => $e->getMessage(),
            ], 500);
        }

        $this->audit->log($request, 'platform.email.replied', 'admin_email_thread', $thread->id, [
            'message_id' => $message->id,
            'to' => collect($message->to_emails ?? [])->pluck('email')->all(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serializeThread($thread->fresh()->load(['messages' => fn ($q) => $q->orderBy('id')]), withMessages: true),
            'message' => 'Reply sent.',
        ]);
    }

    public function compose(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'to' => 'nullable',
            'merchant_ids' => 'nullable|array',
            'merchant_ids.*' => 'integer|min:1',
            'subject' => 'required|string|max:255',
            'body_text' => 'required|string|max:20000',
            'body_html' => 'nullable|string|max:50000',
            'provider_id' => 'nullable|string|max:64',
        ]);

        $validator->after(function ($validator) use ($request): void {
            $to = $request->input('to');
            $merchantIds = $request->input('merchant_ids');
            $hasTo = (is_string($to) && trim($to) !== '')
                || (is_array($to) && array_filter($to, fn ($v) => filled($v)) !== []);
            $hasMerchants = is_array($merchantIds) && $merchantIds !== [];
            if (! $hasTo && ! $hasMerchants) {
                $validator->errors()->add('to', 'Add at least one recipient email or merchant.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $message = $this->sender->compose($validator->validated(), $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send email.',
                'error' => $e->getMessage(),
            ], 500);
        }

        $thread = $message->thread()->with(['messages' => fn ($q) => $q->orderBy('id')])->first();

        $this->audit->log($request, 'platform.email.composed', 'admin_email_thread', $thread?->id, [
            'message_id' => $message->id,
            'to' => collect($message->to_emails ?? [])->pluck('email')->all(),
            'merchant_ids' => $validator->validated()['merchant_ids'] ?? [],
        ]);

        return response()->json([
            'success' => true,
            'data' => $thread ? $this->serializeThread($thread, withMessages: true) : null,
            'message' => 'Email sent.',
        ], 201);
    }

    public function poll(Request $request): JsonResponse
    {
        try {
            $result = $this->imap->pollAll(100);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Mailbox poll failed.',
                'error' => $e->getMessage(),
            ], 500);
        }

        $this->audit->log($request, 'platform.email.polled', 'platform_setting', null, [
            'imported' => $result['imported'],
            'polled' => $result['polled'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => $this->pollMessage($result),
        ]);
    }

    /**
     * @param  array{polled: int, imported: int, providers: list<array{id: string, name: string, imported: int, error: string|null}>}  $result
     */
    private function pollMessage(array $result): string
    {
        if ($result['imported'] > 0) {
            return "Imported {$result['imported']} message(s).";
        }

        $errors = collect($result['providers'] ?? [])
            ->pluck('error')
            ->filter()
            ->values();

        if ($errors->isNotEmpty()) {
            return 'Mailbox poll failed: '.$errors->first();
        }

        if (($result['polled'] ?? 0) === 0) {
            return 'No IMAP-enabled mail providers configured.';
        }

        return 'Mailbox checked. No new messages.';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeThread(AdminEmailThread $thread, bool $withMessages = false): array
    {
        $latest = null;
        if ($thread->relationLoaded('messages') && $thread->messages->isNotEmpty()) {
            $latest = $withMessages ? $thread->messages->last() : $thread->messages->first();
        }

        $payload = [
            'id' => $thread->id,
            'mailbox_provider_id' => $thread->mailbox_provider_id,
            'subject' => $thread->subject,
            'participants' => $thread->participants ?? [],
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'last_direction' => $thread->last_direction,
            'status' => $thread->status,
            'message_count' => $thread->message_count,
            'preview' => $latest?->body_text
                ? \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', (string) $latest->body_text) ?? '', 140)
                : null,
        ];

        if ($withMessages) {
            $payload['messages'] = $thread->messages->map(fn ($message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'from_email' => $message->from_email,
                'from_name' => $message->from_name,
                'to_emails' => $message->to_emails ?? [],
                'cc_emails' => $message->cc_emails ?? [],
                'subject' => $message->subject,
                'body_text' => $message->body_text,
                'body_html' => $message->body_html,
                'message_id' => $message->message_id,
                'status' => $message->status,
                'error' => $message->error,
                'sent_by_admin_id' => $message->sent_by_admin_id,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values();
        }

        return $payload;
    }
}
