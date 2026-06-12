<?php

namespace App\Http\Controllers;

use App\Exceptions\StorefrontAiUnavailableException;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\StorefrontBuilderMessage;
use App\Models\StorefrontBuilderSession;
use App\Models\StorefrontTemplate;
use App\Models\User;
use App\Services\StorefrontBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorefrontBuilderController extends Controller
{
    public function __construct(
        private readonly StorefrontBuilderService $builderService,
    ) {}

    public function startSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => 'nullable|string|max:2000',
        ]);

        try {
            $user = $request->user();
            $session = $this->findOrCreateActiveSession($user);

            if (! empty($data['prompt'])) {
                $this->appendUserMessage($session, $data['prompt']);
                $this->processUserMessage($session, $data['prompt']);
            } elseif ($session->messages()->count() === 0) {
                $this->appendAssistantMessage(
                    $session,
                    $this->builderService->conversationalReply($this->sessionContext($session), ''),
                    ['type' => 'welcome'],
                );
            }

            return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
        } catch (StorefrontAiUnavailableException $exception) {
            return $this->aiUnavailableResponse($exception);
        }
    }

    public function currentSession(Request $request): JsonResponse
    {
        $session = StorefrontBuilderSession::query()
            ->with(['messages', 'store.merchant'])
            ->where('user_id', $request->user()->id)
            ->whereNotIn('status', ['published'])
            ->latest('updated_at')
            ->first();

        if (! $session) {
            return response()->json([
                'session' => null,
            ]);
        }

        return response()->json($this->formatSessionPayload($session));
    }

    public function sendMessage(Request $request, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        try {
            $session = $this->findOwnedSession($request, $sessionId);
            $this->appendUserMessage($session, $data['message']);
            $this->processUserMessage($session, $data['message']);

            return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
        } catch (StorefrontAiUnavailableException $exception) {
            return $this->aiUnavailableResponse($exception);
        }
    }

    public function selectTemplate(Request $request, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'string', Rule::in(StorefrontTemplate::activeConcreteIds())],
            'source' => 'nullable|string|in:merchant_selected,ai_selected',
        ]);

        $session = $this->findOwnedSession($request, $sessionId);
        $session->selected_template_id = $data['template_id'];
        $session->status = 'template_recommendation';
        $session->save();

        if ($session->store) {
            $session->store->storefront_template_id = $data['template_id'];
            $session->store->save();
        }

        $this->appendAssistantMessage(
            $session,
            'Great choice. I can generate a first draft with hero copy, about section, FAQs, SEO, and sample products for this template.',
            [
                'type' => 'template_selected',
                'template_id' => $data['template_id'],
                'source' => $data['source'] ?? 'merchant_selected',
            ],
        );

        return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
    }

    public function generateDraft(Request $request, int $sessionId): JsonResponse
    {
        try {
            $session = $this->findOwnedSession($request, $sessionId);
            $store = $this->ensureStoreForSession($session, $request->user());

            if ($session->selected_template_id) {
                $store->storefront_template_id = $session->selected_template_id;
                $store->save();
            }

            $storefront = $this->builderService->synthesizeStorefront($store->fresh('merchant'));
            $generationId = (string) Str::uuid();

            $store->storefront_content = $storefront;
            $store->storefront_generation_id = $generationId;
            $store->save();

            $session->store_id = $store->id;
            $session->storefront_snapshot = $storefront;
            $session->status = 'content_generated';
            $session->save();

            $this->appendAssistantMessage(
                $session,
                'Your storefront draft is ready. Preview it on the right, or ask me to refine the hero, about section, FAQ, or SEO.',
                [
                    'type' => 'draft_generated',
                    'generation_id' => $generationId,
                ],
            );

            return response()->json([
                ...$this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])),
                'generation_id' => $generationId,
                'storefront' => $storefront,
            ]);
        } catch (StorefrontAiUnavailableException $exception) {
            return $this->aiUnavailableResponse($exception);
        }
    }

    public function applyEdit(Request $request, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'instruction' => 'required|string|max:2000',
        ]);

        try {
            $session = $this->findOwnedSession($request, $sessionId);
            $store = $session->store;

            if (! $store) {
                return response()->json([
                    'message' => 'Generate a draft before applying chat edits.',
                ], 422);
            }

            $storefront = $session->storefront_snapshot
                ?? $store->storefront_content
                ?? $this->builderService->synthesizeStorefront($store->load('merchant'));

            $result = $this->builderService->applyChatEdit($storefront, $data['instruction']);

            $store->storefront_content = $result['storefront'];
            $store->save();

            $session->storefront_snapshot = $result['storefront'];
            $session->status = 'review_ready';
            $session->save();

            $summary = ! empty($result['assistant_message'])
                ? $result['assistant_message']
                : ($result['changed_paths']
                    ? 'Updated: '.implode(', ', $result['changed_paths']).'.'
                    : 'I reviewed your request but did not change any protected fields.');

            $this->appendAssistantMessage(
                $session,
                $summary,
                [
                    'type' => 'edit_applied',
                    'changed_paths' => $result['changed_paths'],
                ],
            );

            return response()->json([
                ...$this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])),
                'storefront' => $result['storefront'],
                'changed_paths' => $result['changed_paths'],
            ]);
        } catch (StorefrontAiUnavailableException $exception) {
            return $this->aiUnavailableResponse($exception);
        }
    }

    private function findOrCreateActiveSession(User $user): StorefrontBuilderSession
    {
        $existing = StorefrontBuilderSession::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['published'])
            ->latest('updated_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        $store = Store::with('merchant')
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $user->id))
            ->latest()
            ->first();

        return StorefrontBuilderSession::create([
            'user_id' => $user->id,
            'store_id' => $store?->id,
            'status' => $store ? 'template_recommendation' : 'collecting_requirements',
            'business_profile' => $store ? [
                'business_name' => $store->name,
                'description' => $store->description,
                'industry' => $store->merchant?->industry,
                'brand_color' => $store->brand_color,
                'tone' => [],
            ] : [],
            'selected_template_id' => $store?->storefront_template_id !== 'ai_pick'
                ? $store?->storefront_template_id
                : null,
            'storefront_snapshot' => $store?->storefront_content,
        ]);
    }

    private function processUserMessage(StorefrontBuilderSession $session, string $message): void
    {
        if (! $this->builderService->isSubstantiveMessage($message)) {
            $this->appendAssistantMessage(
                $session,
                $this->builderService->conversationalReply($this->sessionContext($session), $message),
                ['type' => 'conversation'],
            );

            return;
        }

        $profile = $this->builderService->extractBusinessProfileFromMessage(
            $message,
            $session->business_profile ?? [],
        );
        $session->business_profile = $profile;
        $session->last_intent = $message;

        $hasMinimum = ! empty($profile['business_name'])
            && ! empty($profile['description'])
            && strlen((string) $profile['description']) >= 10;

        if ($hasMinimum && ! $session->store_id) {
            $store = $this->createStoreFromProfile($session->user, $profile);
            $session->store_id = $store->id;
            $session->status = 'template_recommendation';
        } elseif ($hasMinimum) {
            $session->status = 'template_recommendation';
            if ($session->store) {
                $session->store->fill([
                    'name' => $profile['business_name'] ?? $session->store->name,
                    'description' => $profile['description'] ?? $session->store->description,
                    'brand_color' => $profile['brand_color'] ?? $session->store->brand_color,
                ])->save();
                $session->store->merchant?->fill([
                    'business_name' => $profile['business_name'] ?? $session->store->merchant?->business_name,
                    'industry' => $profile['industry'] ?? $session->store->merchant?->industry,
                ])->save();
            }
        } else {
            $session->status = 'collecting_requirements';
        }

        $session->save();
        $session->load('store.merchant');

        $recommendations = $hasMinimum
            ? $this->recommendTemplatesForProfile($profile)
            : [];

        if (! $hasMinimum) {
            $missing = [];
            if (empty($profile['business_name'])) {
                $missing[] = 'business name';
            }
            if (empty($profile['description']) || strlen((string) $profile['description']) < 10) {
                $missing[] = 'short description of what you sell';
            }

            $this->appendAssistantMessage(
                $session,
                'Thanks — I still need your '.implode(' and ', $missing).'. For example: "Glow Rituals is an organic skincare brand for busy professionals."',
                ['type' => 'requirements_request', 'profile' => $profile],
            );

            return;
        }

        $agentTurn = $this->builderService->planBuilderTurn(
            $message,
            $this->sessionContext($session),
            $profile,
            $recommendations,
        );

        $toolResults = $this->executeBuilderToolCalls(
            $session,
            $agentTurn['tool_calls'],
            $recommendations,
        );

        $this->appendAssistantMessage(
            $session,
            $agentTurn['assistant_message'],
            [
                'type' => 'agent_turn',
                'plan' => $agentTurn['plan'] ?? [],
                'tool_calls' => $agentTurn['tool_calls'],
                'tool_results' => $toolResults,
                'recommendations' => $recommendations,
                'profile' => $profile,
            ],
        );
    }

    private function aiUnavailableResponse(StorefrontAiUnavailableException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
        ], 503);
    }

    /**
     * @param  list<array{name: string, arguments: array<string, mixed>}>  $toolCalls
     * @param  list<array<string, mixed>>  $recommendations
     * @return list<array<string, mixed>>
     */
    private function executeBuilderToolCalls(
        StorefrontBuilderSession $session,
        array $toolCalls,
        array $recommendations,
    ): array {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $name = $toolCall['name'] ?? null;
            $arguments = is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [];

            if ($name === 'recommend_templates') {
                $results[] = [
                    'name' => $name,
                    'ok' => true,
                    'recommendations_count' => count($recommendations),
                ];
                continue;
            }

            if ($name === 'ask_clarifying_question') {
                $results[] = [
                    'name' => $name,
                    'ok' => true,
                    'question' => $arguments['question'] ?? null,
                ];
                continue;
            }

            if ($name === 'select_template') {
                $templateId = $arguments['template_id'] ?? null;
                if (! is_string($templateId) || ! in_array($templateId, StorefrontTemplate::activeConcreteIds(), true)) {
                    $results[] = [
                        'name' => $name,
                        'ok' => false,
                        'error' => 'invalid_template_id',
                    ];
                    continue;
                }

                $session->selected_template_id = $templateId;
                $session->status = 'template_recommendation';
                $session->save();

                if ($session->store) {
                    $session->store->storefront_template_id = $templateId;
                    $session->store->save();
                }

                $results[] = [
                    'name' => $name,
                    'ok' => true,
                    'template_id' => $templateId,
                    'source' => $arguments['source'] ?? 'ai_selected',
                ];
                continue;
            }

            if ($name === 'generate_draft') {
                $results[] = $this->executeGenerateDraftTool($session, $recommendations);
            }
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $recommendations
     * @return array<string, mixed>
     */
    private function executeGenerateDraftTool(StorefrontBuilderSession $session, array $recommendations): array
    {
        if (! $session->selected_template_id) {
            $topTemplateId = $recommendations[0]['template_id'] ?? (StorefrontTemplate::activeConcreteIds()[0] ?? null);
            if (is_string($topTemplateId) && in_array($topTemplateId, StorefrontTemplate::activeConcreteIds(), true)) {
                $session->selected_template_id = $topTemplateId;
                $session->status = 'template_recommendation';
                $session->save();
            }
        }

        if (! $session->selected_template_id) {
            return [
                'name' => 'generate_draft',
                'ok' => false,
                'error' => 'missing_selected_template',
            ];
        }

        $store = $this->ensureStoreForSession($session, $session->user);
        $store->storefront_template_id = $session->selected_template_id;
        $store->save();

        $storefront = $this->builderService->synthesizeStorefront($store->fresh('merchant'));
        $generationId = (string) Str::uuid();

        $store->storefront_content = $storefront;
        $store->storefront_generation_id = $generationId;
        $store->save();

        $session->store_id = $store->id;
        $session->storefront_snapshot = $storefront;
        $session->status = 'content_generated';
        $session->save();

        return [
            'name' => 'generate_draft',
            'ok' => true,
            'generation_id' => $generationId,
            'template_id' => $session->selected_template_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return list<array{template_id: string, score: float, reason: string}>
     */
    private function recommendTemplatesForProfile(array $profile): array
    {
        $prompt = trim(($profile['business_name'] ?? '').' '.($profile['description'] ?? ''));
        $industry = $profile['industry'] ?? 'other';
        $tone = $profile['tone'] ?? [];

        $request = Request::create('/', 'POST', [
            'prompt' => $prompt,
            'industry' => $industry,
            'tone' => $tone,
            'limit' => 4,
        ]);

        $response = app(StorefrontTemplateController::class)->recommend($request);
        $payload = $response->getData(true);

        return $payload['recommendations'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function createStoreFromProfile(User $user, array $profile): Store
    {
        $existing = Store::with('merchant')
            ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $user->id))
            ->first();

        if ($existing) {
            return $existing;
        }

        $businessName = (string) ($profile['business_name'] ?? 'My Store');
        $industry = (string) ($profile['industry'] ?? 'other');
        $brandColor = (string) ($profile['brand_color'] ?? '#0E7C66');

        $merchant = Merchant::firstOrCreate(
            ['owner_user_id' => $user->id],
            [
                'business_name' => $businessName,
                'slug' => $this->uniqueMerchantSlug($businessName),
                'contact_name' => $user->name,
                'email' => $user->email,
                'industry' => $industry,
                'status' => 'pending',
                'subscription_plan' => 'starter',
                'subscription_status' => 'trialing',
            ],
        );

        $merchant->fill([
            'business_name' => $businessName,
            'contact_name' => $user->name,
            'email' => $user->email,
            'industry' => $industry,
        ])->save();

        $slug = $this->uniqueStoreSlug($businessName);
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');

        return Store::create([
            'merchant_id' => $merchant->id,
            'name' => $businessName,
            'slug' => $slug,
            'status' => 'draft',
            'primary_domain' => "{$slug}.{$platformDomain}",
            'description' => (string) ($profile['description'] ?? ''),
            'brand_color' => $brandColor,
            'logo_url' => null,
            'storefront_template_id' => 'ai_pick',
        ])->load('merchant');
    }

    private function ensureStoreForSession(StorefrontBuilderSession $session, User $user): Store
    {
        if ($session->store) {
            return $session->store->load('merchant');
        }

        $profile = $session->business_profile ?? [];
        if (empty($profile['business_name']) || empty($profile['description'])) {
            abort(422, 'Add your business name and description before generating a draft.');
        }

        $store = $this->createStoreFromProfile($user, $profile);
        $session->store_id = $store->id;
        $session->save();

        return $store;
    }

    private function appendUserMessage(StorefrontBuilderSession $session, string $content): void
    {
        StorefrontBuilderMessage::create([
            'session_id' => $session->id,
            'role' => 'user',
            'content' => $content,
            'payload' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function appendAssistantMessage(StorefrontBuilderSession $session, string $content, ?array $payload = null): void
    {
        StorefrontBuilderMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $content,
            'payload' => $payload,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionContext(StorefrontBuilderSession $session): array
    {
        $profile = $session->business_profile ?? [];

        return [
            'status' => $session->status,
            'business_name' => $profile['business_name'] ?? $session->store?->name,
            'industry' => $profile['industry'] ?? $session->store?->merchant?->industry,
            'has_store' => (bool) $session->store_id,
            'selected_template_id' => $session->selected_template_id,
            'has_storefront_draft' => ! empty($session->storefront_snapshot),
        ];
    }

    private function findOwnedSession(Request $request, int $sessionId): StorefrontBuilderSession
    {
        $session = StorefrontBuilderSession::with(['messages', 'store.merchant'])
            ->where('id', $sessionId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $session) {
            abort(404, 'Builder session not found.');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSessionPayload(StorefrontBuilderSession $session): array
    {
        $profile = $session->business_profile ?? [];
        $recommendations = $session->status !== 'collecting_requirements'
            ? $this->recommendTemplatesForProfile($profile)
            : [];

        $store = $session->store;

        return [
            'session' => [
                'id' => (string) $session->id,
                'status' => $session->status,
                'business_profile' => $profile,
                'selected_template_id' => $session->selected_template_id,
                'storefront_snapshot' => $session->storefront_snapshot,
                'store' => $store ? $this->formatStore($store) : null,
                'messages' => $session->messages
                    ->sortBy('created_at')
                    ->values()
                    ->map(fn (StorefrontBuilderMessage $message) => [
                        'id' => (string) $message->id,
                        'role' => $message->role,
                        'content' => $message->content,
                        'payload' => $message->payload,
                        'created_at' => $message->created_at?->toIso8601String(),
                    ])
                    ->all(),
                'recommendations' => $recommendations,
                'updated_at' => $session->updated_at?->toIso8601String(),
            ],
        ];
    }

    private function formatStore(Store $store): array
    {
        $store->loadMissing('merchant');
        $platformDomain = config('storehause.platform_domain', 'yrayhostings.com.ng');
        $subdomainHost = "{$store->slug}.{$platformDomain}";

        return [
            'id' => (string) $store->id,
            'slug' => $store->slug,
            'business_name' => $store->name,
            'industry' => $store->merchant?->industry ?? 'other',
            'description' => $store->description ?? '',
            'brand_color' => $store->brand_color ?? '#0E7C66',
            'logo_url' => $store->logo_url,
            'storefront_template_id' => $store->storefront_template_id ?? 'ai_pick',
            'subdomain' => $store->slug,
            'subdomain_host' => $subdomainHost,
            'primary_domain' => $store->primary_domain ?? $subdomainHost,
        ];
    }

    private function uniqueMerchantSlug(string $name): string
    {
        return $this->uniqueSlug($name, fn (string $slug): bool => Merchant::where('slug', $slug)->exists());
    }

    private function uniqueStoreSlug(string $name): string
    {
        return $this->uniqueSlug($name, fn (string $slug): bool => Store::where('slug', $slug)->exists());
    }

    private function uniqueSlug(string $name, callable $exists): string
    {
        $base = Str::slug($name) ?: 'store';
        $slug = $base;
        $suffix = 2;

        while ($exists($slug)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
