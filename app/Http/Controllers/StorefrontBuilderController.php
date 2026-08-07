<?php

namespace App\Http\Controllers;

use App\Exceptions\StorefrontAiUnavailableException;
use App\Http\Controllers\Concerns\InvalidatesApiCache;
use App\Models\Merchant;
use App\Models\Store;
use App\Models\StorefrontBuilderMessage;
use App\Models\StorefrontBuilderSession;
use App\Models\StorefrontTemplate;
use App\Models\User;
use App\Services\AgentExecutionLogService;
use App\Services\MerchantUsageEnforcementService;
use App\Services\StorefrontBlockService;
use App\Services\StorefrontBuilderService;
use App\Services\StorefrontPublishService;
use App\Services\StoreProductService;
use App\Services\WorkbenchProjectStorage;
use App\Support\SseStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StorefrontBuilderController extends Controller
{
    use InvalidatesApiCache;

    public function __construct(
        private readonly StorefrontBuilderService $builderService,
        private readonly StoreProductService $productService,
        private readonly StorefrontPublishService $publishService,
        private readonly StorefrontBlockService $blockService,
        private readonly WorkbenchProjectStorage $projectStorage,
        private readonly MerchantUsageEnforcementService $enforcement,
        private readonly AgentExecutionLogService $executionLogs,
    ) {}

    public function startSession(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = $this->findOrCreateActiveSession($user);

        if ($session->messages()->count() === 0) {
            if ($session->store_id && ! empty($session->business_profile['business_name'])) {
                $name = (string) $session->business_profile['business_name'];
                $this->appendAssistantMessage(
                    $session,
                    "Welcome! I've loaded {$name}. Next: pick a look for your site (or say \"build my website\"), then add products and publish when you're ready.",
                    [
                        'type' => 'welcome',
                        'from_onboarding' => true,
                        'suggested_actions' => [
                            ['label' => 'Build my website', 'action' => 'send_message', 'message' => 'build my website'],
                            ['label' => 'Show me template options', 'action' => 'send_message', 'message' => 'recommend templates for my store'],
                        ],
                    ],
                );
            } else {
                $this->appendAssistantMessage(
                    $session,
                    'Hi! Tell me about your business — what you sell, who it\'s for, and the vibe you want. I\'ll design and build your website.',
                    ['type' => 'welcome'],
                );
            }
        }

        $this->invalidateBuilderApiCache($session);

        return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
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
            'message' => 'required|string|max:8000',
            'business_profile' => 'nullable|array',
            'status' => 'nullable|string|in:collecting_requirements,template_recommendation,content_generated,products_pending,review_ready,published',
            'assistant_message' => 'nullable|string|max:4000',
            'assistant_payload' => 'nullable|array',
            'selected_template_id' => ['nullable', 'string', Rule::in(StorefrontTemplate::activeConcreteIds())],
            'storefront_snapshot' => 'nullable|array',
            'brand_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'color_label' => 'nullable|string|max:120',
            'logo_url' => 'nullable|url|max:2048',
            'media_updates' => 'nullable|array',
            'apply_stock_images' => 'nullable|boolean',
        ]);

        $session = $this->findOwnedSession($request, $sessionId);
        $this->appendUserMessage($session, $data['message']);

        if ($this->applyVisualBuilderUpdates($session, $data)) {
            $this->invalidateBuilderApiCache($session);

            return response()->json([
                ...$this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])),
                'storefront' => $session->store
                    ? $this->productService->mergeIntoStorefront($session->storefront_snapshot ?? [], $session->store, activeOnly: true)
                    : $session->storefront_snapshot,
            ]);
        }

        if (! empty($data['assistant_message'])) {
            $this->persistClientTurn($session, $request->user(), $data);
        } elseif (! empty($data['storefront_snapshot']) && is_array($data['storefront_snapshot'])) {
            $this->persistStorefrontSnapshot($session, $request->user(), $data);
        } else {
            // Merchant builder agents/tools run in Next.js. Laravel only persists
            // client turns (assistant_message / storefront_snapshot) or visual extras.
            $this->appendAssistantMessage(
                $session,
                'I could not process that update. Please try again from the website builder.',
                [
                    'type' => 'conversation',
                    'error' => 'client_turn_required',
                ],
            );
            $session->save();
        }

        $this->invalidateBuilderApiCache($session);

        return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
    }

    public function saveSnapshot(Request $request, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'storefront_snapshot' => 'required|array',
            'status' => 'nullable|string|in:collecting_requirements,template_recommendation,content_generated,products_pending,review_ready,published',
        ]);

        $session = $this->findOwnedSession($request, $sessionId);
        $this->persistStorefrontSnapshot($session, $request->user(), $data, syncStoreDraft: false);

        $this->invalidateBuilderApiCache($session);

        return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
    }

    public function saveProject(Request $request, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'custom_files' => 'required|array',
            'custom_files.*.path' => 'required|string|max:500',
            'custom_files.*.content' => 'required|string',
            'custom_files.*.encoding' => 'nullable|string|in:base64',
            'edit_metadata' => 'nullable|array',
            'edit_metadata.locked_paths' => 'nullable|array',
            'edit_metadata.locked_paths.*' => 'string|max:500',
        ]);

        $session = $this->findOwnedSession($request, $sessionId);

        $pointer = $this->projectStorage->save(
            (int) $session->id,
            $data['custom_files'],
            is_array($data['edit_metadata'] ?? null) ? $data['edit_metadata'] : null,
        );

        $snapshot = is_array($session->storefront_snapshot) ? $session->storefront_snapshot : [];
        unset($snapshot['custom_files'], $snapshot['custom_code']);
        $snapshot['custom_project'] = $pointer;

        if (! empty($data['edit_metadata']) && is_array($data['edit_metadata'])) {
            $snapshot['edit_metadata'] = array_merge(
                is_array($snapshot['edit_metadata'] ?? null) ? $snapshot['edit_metadata'] : [],
                $data['edit_metadata'],
            );
        }

        $session->storefront_snapshot = $snapshot;
        $this->publishService->reconnectAndSaveModel($session);

        $this->invalidateBuilderApiCache($session);

        return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
    }

    public function getProject(Request $request, int $sessionId): JsonResponse
    {
        $session = $this->findOwnedSession($request, $sessionId);
        $project = $this->projectStorage->load((int) $session->id);

        if ($project === null) {
            return response()->json([
                'custom_files' => [],
                'edit_metadata' => ['locked_paths' => []],
                'custom_project' => null,
            ]);
        }

        return response()->json($project);
    }

    public function clearMessages(Request $request, int $sessionId): JsonResponse
    {
        $session = $this->findOwnedSession($request, $sessionId);
        $session->messages()->delete();

        $this->appendAssistantMessage(
            $session,
            'Hi! Tell me about your business — what you sell, who it\'s for, and the vibe you want. I\'ll design and build your website.',
            ['type' => 'welcome'],
        );

        $this->invalidateBuilderApiCache($session);

        return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
    }

    public function selectTemplate(Request $request, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'string', Rule::in(StorefrontTemplate::activeConcreteIds())],
            'source' => 'nullable|string|in:merchant_selected,ai_selected',
        ]);

        $session = $this->findOwnedSession($request, $sessionId);
        $session->selected_template_id = $data['template_id'];
        $session->save();

        if ($session->store) {
            $session->store->storefront_template_id = $data['template_id'];
            $session->store->save();
        }

        $hasDraft = ! empty($session->storefront_snapshot) && $session->store;

        if ($hasDraft) {
            $recommendations = $this->recommendTemplatesForProfile($session->business_profile ?? []);
            $result = $this->executeGenerateDraftTool($session, $recommendations);

            if ($result['ok'] ?? false) {
                $templateLabel = $this->templateLabel($data['template_id']);
                $this->appendAssistantMessage(
                    $session,
                    "Done — I refreshed your website with a {$templateLabel} look. Check the preview on the right, then tell me what to refine.",
                    [
                        'type' => 'website_generated',
                        'template_id' => $data['template_id'],
                        'source' => $data['source'] ?? 'merchant_selected',
                        'generation_id' => $result['generation_id'] ?? null,
                    ],
                );

                $session = $session->fresh(['messages', 'store.merchant']);

                $this->invalidateBuilderApiCache($session);

                return response()->json([
                    ...$this->formatSessionPayload($session),
                    'storefront' => $session->store
                        ? $this->productService->mergeIntoStorefront($session->storefront_snapshot ?? [], $session->store, activeOnly: true)
                        : $session->storefront_snapshot,
                ]);
            }

            // Regenerate failed — still point the snapshot at the selected template so preview switches.
            $snapshot = is_array($session->storefront_snapshot) ? $session->storefront_snapshot : [];
            data_set($snapshot, 'template.id', $data['template_id']);
            data_set($snapshot, 'template.source', $data['source'] ?? 'merchant_selected');
            $snapshot['palette'] = $this->builderService->defaultStorefrontPalette(
                $data['template_id'],
                $session->store?->brand_color,
            );
            $snapshot = $this->blockService->ensureAllPageBlocksOnStorefront($snapshot);
            $session->storefront_snapshot = $snapshot;
            $session->status = 'content_generated';
            $session->save();

            if ($session->store) {
                $this->publishService->persistDraft($session->store, $snapshot);
            }

            $this->appendAssistantMessage(
                $session,
                'Got it — I switched the design. Check the preview on the right, then tell me what to refine.',
                [
                    'type' => 'design_selected',
                    'template_id' => $data['template_id'],
                    'source' => $data['source'] ?? 'merchant_selected',
                ],
            );

            $this->invalidateBuilderApiCache($session);

            $session = $session->fresh(['messages', 'store.merchant']);

            return response()->json([
                ...$this->formatSessionPayload($session),
                'storefront' => $session->store
                    ? $this->productService->mergeIntoStorefront($session->storefront_snapshot ?? [], $session->store, activeOnly: true)
                    : $session->storefront_snapshot,
            ]);
        }

        $session->status = 'template_recommendation';
        $session->save();

        $this->appendAssistantMessage(
            $session,
            'Got it. Say “build my website” and I will generate your first draft with homepage copy, about section, FAQs, SEO, and starter products.',
            [
                'type' => 'design_selected',
                'template_id' => $data['template_id'],
                'source' => $data['source'] ?? 'merchant_selected',
            ],
        );

        $this->invalidateBuilderApiCache($session);

        return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
    }

    public function generateDraft(Request $request, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'storefront' => 'nullable|array',
            'selected_template_id' => ['nullable', 'string', Rule::in(StorefrontTemplate::activeConcreteIds())],
            'skip_assistant_message' => 'nullable|boolean',
            'business_profile' => 'nullable|array',
        ]);

        $session = $this->findOwnedSession($request, $sessionId);
        $store = $this->ensureStoreForSession($session, $request->user());
        $this->enforceAiUsage($store);
        $this->bindExecutionLogContext($request, $session, $store);

        if (! empty($data['business_profile']) && is_array($data['business_profile'])) {
            $session->business_profile = $data['business_profile'];
            $session->save();
        }

        $this->syncStoreFromProfile($store, $session->business_profile ?? []);
        $templateId = $data['selected_template_id'] ?? $session->selected_template_id;

        if ($templateId) {
            $session->selected_template_id = $templateId;
            $store->storefront_template_id = $templateId;
            $store->save();
        }

        if (! empty($data['storefront'])) {
            $storefront = $data['storefront'];
        } else {
            try {
                $storefront = $this->builderService->synthesizeStorefront($store->fresh('merchant'));
            } catch (StorefrontAiUnavailableException $exception) {
                return $this->aiUnavailableResponse($exception);
            }
        }

        $generationId = (string) Str::uuid();
        $storefront = $this->productService->extractEmbeddedProducts($store, $storefront);
        $store->storefront_generation_id = $generationId;
        $this->publishService->persistDraft($store, $storefront);

        $session->store_id = $store->id;
        $session->storefront_snapshot = $storefront;
        $session->status = 'content_generated';
        $session->save();

        $mergedStorefront = $this->productService->mergeIntoStorefront($storefront, $store, activeOnly: true);

        if (! ($data['skip_assistant_message'] ?? false)) {
            $this->appendAssistantMessage(
                $session,
                'Your website is ready. Preview it on the right, then tell me what to refine — headline, about section, CTA, or SEO.',
                [
                    'type' => 'website_generated',
                    'generation_id' => $generationId,
                ],
            );
        }

        $this->invalidateBuilderApiCache($session);

        return response()->json([
            ...$this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])),
            'generation_id' => $generationId,
            'storefront' => $mergedStorefront,
        ]);
    }

    public function generateDraftStream(Request $request, int $sessionId): StreamedResponse
    {
        $data = $request->validate([
            'storefront' => 'nullable|array',
            'selected_template_id' => ['nullable', 'string', Rule::in(StorefrontTemplate::activeConcreteIds())],
        ]);

        $session = $this->findOwnedSession($request, $sessionId);
        $store = $this->ensureStoreForSession($session, $request->user());
        $this->syncStoreFromProfile($store, $session->business_profile ?? []);
        $templateId = $data['selected_template_id'] ?? $session->selected_template_id;

        if ($templateId) {
            $session->selected_template_id = $templateId;
            $store->storefront_template_id = $templateId;
            $store->save();
        }

        // Enforce AI usage BEFORE streaming (same as generateDraft)
        $this->enforceAiUsage($store);
        $this->bindExecutionLogContext($request, $session, $store);

        return SseStream::response(function ($emit) use ($data, $session, $store) {
            try {
                if (! empty($data['storefront'])) {
                    $storefront = $data['storefront'];
                    SseStream::log($emit, 'builder', 'generate', 'Using provided content', 'Applying your custom storefront content.');
                } else {
                    SseStream::log($emit, 'interpreter', 'analyze', 'Analyzing your business', 'Understanding your brand and products...');
                    sleep(0); // flush

                    SseStream::log($emit, 'design-director', 'design', 'Picking the best design', 'Matching a design to your business type and style...');

                    try {
                        $storefront = $this->builderService->synthesizeStorefront($store->fresh('merchant'));
                    } catch (StorefrontAiUnavailableException $e) {
                        SseStream::error($emit, $e->getMessage());

                        return;
                    }

                    SseStream::log($emit, 'storefront-writer', 'write', 'Writing your website content', 'Creating hero, about, value props, FAQs, and SEO...');
                }

                $generationId = (string) Str::uuid();

                SseStream::log($emit, 'builder', 'save', 'Saving your draft', 'Storing your storefront and preparing preview...');

                $storefront = $this->productService->extractEmbeddedProducts($store, $storefront);
                $store->storefront_generation_id = $generationId;
                $this->publishService->persistDraft($store, $storefront);

                $session->store_id = $store->id;
                $session->storefront_snapshot = $storefront;
                $session->status = 'content_generated';
                if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql') {
                    \Illuminate\Support\Facades\DB::reconnect();
                }
                $session->save();

                $mergedStorefront = $this->productService->mergeIntoStorefront($storefront, $store, activeOnly: true);

                $this->appendAssistantMessage(
                    $session,
                    'Your website is ready. Preview it on the right, then tell me what to refine — headline, about section, CTA, or SEO.',
                    [
                        'type' => 'website_generated',
                        'generation_id' => $generationId,
                    ],
                );

                SseStream::log($emit, 'builder', 'done', 'Website ready', 'Your storefront is live in preview.');

                $this->invalidateBuilderApiCache($session);

                SseStream::complete($emit, [
                    ...$this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])),
                    'generation_id' => $generationId,
                    'storefront' => $mergedStorefront,
                ]);
            } catch (\Throwable $e) {
                SseStream::error($emit, $e->getMessage());
            }
        });
    }

    public function applyEdit(Request $request, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'instruction' => 'required|string|max:8000',
            'storefront' => 'required|array',
            'changed_paths' => 'nullable|array',
            'changed_paths.*' => 'string',
            'assistant_message' => 'nullable|string|max:4000',
        ]);

        $session = $this->findOwnedSession($request, $sessionId);
        $store = $session->store;

        if (! $store) {
            return response()->json([
                'message' => 'Generate a website before applying chat edits.',
            ], 422);
        }

        $this->enforceAiUsage($store);

        $storefront = $data['storefront'];
        $changedPaths = $data['changed_paths'] ?? [];
        $summary = $data['assistant_message']
            ?? $this->builderService->describeStorefrontEdit($changedPaths);

        unset($storefront['products']);
        $this->publishService->persistDraft($store, $storefront);

        $session->storefront_snapshot = $storefront;
        $session->status = 'review_ready';
        $session->save();

        $mergedStorefront = $this->productService->mergeIntoStorefront($storefront, $store, activeOnly: true);

        $this->appendAssistantMessage(
            $session,
            $summary,
            [
                'type' => 'website_refined',
                'changed_paths' => $changedPaths,
            ],
        );

        $this->invalidateBuilderApiCache($session);

        return response()->json([
            ...$this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])),
            'storefront' => $mergedStorefront,
            'changed_paths' => $changedPaths,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistClientTurn(StorefrontBuilderSession $session, User $user, array $data): void
    {
        if (! empty($data['business_profile']) && is_array($data['business_profile'])) {
            $session->business_profile = $data['business_profile'];
        }

        if (! empty($data['status'])) {
            $session->status = $data['status'];
        }

        if (! empty($data['selected_template_id'])) {
            $session->selected_template_id = $data['selected_template_id'];
        }

        $profile = $session->business_profile ?? [];
        $hasMinimum = ! empty($profile['business_name'])
            && ! empty($profile['description'])
            && strlen((string) $profile['description']) >= 10;

        if ($hasMinimum && ! $session->store_id) {
            $store = $this->createStoreFromProfile($user, $profile);
            $session->store_id = $store->id;
        } elseif ($hasMinimum && $session->store) {
            // Store name/industry are owned by Settings (and update_store_profile).
            // Re-applying a stale session profile here was overwriting merchant edits.
            $session->store->fill([
                'description' => $profile['description'] ?? $session->store->description,
                'brand_color' => $profile['brand_color'] ?? $session->store->brand_color,
            ])->save();
        }

        if (! empty($data['selected_template_id']) && $session->selected_template_id && $session->store) {
            $session->store->storefront_template_id = $session->selected_template_id;
            $session->store->save();
        }

        if (! empty($data['storefront_snapshot']) && is_array($data['storefront_snapshot'])) {
            $this->persistStorefrontSnapshot($session, $user, $data);
        } else {
            \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql'
                ? \Illuminate\Support\Facades\DB::reconnect()
                : null;
            $session->save();
        }

        $this->appendAssistantMessage(
            $session,
            (string) $data['assistant_message'],
            is_array($data['assistant_payload'] ?? null) ? $data['assistant_payload'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistStorefrontSnapshot(
        StorefrontBuilderSession $session,
        User $user,
        array $data,
        bool $syncStoreDraft = true,
    ): void {
        if (empty($data['storefront_snapshot']) || ! is_array($data['storefront_snapshot'])) {
            return;
        }

        $storefront = $this->publishService->compactSessionSnapshot($data['storefront_snapshot']);

        $this->projectStorage->extractAndPersist((int) $session->id, $storefront);

        if (! empty($data['selected_template_id'])) {
            $session->selected_template_id = $data['selected_template_id'];
        }

        $profile = $session->business_profile ?? [];
        $hasMinimum = ! empty($profile['business_name'])
            && ! empty($profile['description'])
            && strlen((string) $profile['description']) >= 10;

        if ($hasMinimum && ! $session->store_id) {
            $store = $this->createStoreFromProfile($user, $profile);
            $session->store_id = $store->id;
            $session->load('store.merchant');
        }

        if ($session->store && $syncStoreDraft) {
            $storefront = $this->productService->extractEmbeddedProducts($session->store, $storefront);

            $templateId = $data['selected_template_id'] ?? $session->selected_template_id;
            if (! empty($templateId) && $session->selected_template_id) {
                data_set($storefront, 'template.id', $session->selected_template_id);
                data_set($storefront, 'template.source', 'merchant_selected');
            }

            $snapshotTemplateId = data_get($storefront, 'template.id');
            if (is_string($snapshotTemplateId) && $snapshotTemplateId !== '' && $snapshotTemplateId !== 'ai_pick') {
                $session->store->storefront_template_id = $snapshotTemplateId;
            }

            $session->store->storefront_generation_id = (string) Str::uuid();
            $this->publishService->persistDraft($session->store, $storefront);
        }

        $session->storefront_snapshot = $storefront;

        if (! empty($data['status'])) {
            $session->status = $data['status'];
        } elseif ($session->store && $session->status !== 'published') {
            $session->status = 'content_generated';
        }

        $this->publishService->reconnectAndSaveModel($session);
    }

    private function findOrCreateActiveSession(User $user): StorefrontBuilderSession
    {
        $existing = StorefrontBuilderSession::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['published'])
            ->latest('updated_at')
            ->first();

        if ($existing) {
            if (! $existing->store_id) {
                $store = Store::with('merchant')
                    ->whereHas('merchant', fn ($query) => $query->where('owner_user_id', $user->id))
                    ->latest()
                    ->first();

                if ($store) {
                    $existing->store_id = $store->id;
                    $existing->status = 'template_recommendation';
                    if (empty($existing->business_profile['business_name'])) {
                        $existing->business_profile = [
                            'business_name' => $store->name,
                            'description' => $store->description,
                            'industry' => $store->merchant?->industry,
                            'brand_color' => $store->brand_color,
                            'tone' => [],
                        ];
                    }
                    $existing->save();
                }
            }

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
            'storefront_snapshot' => $store ? $this->publishService->resolveDraft($store) : null,
        ]);
    }

    private function aiUnavailableResponse(StorefrontAiUnavailableException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
        ], 503);
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
        $this->syncStoreFromProfile($store, $session->business_profile ?? []);
        $store->storefront_template_id = $session->selected_template_id;
        $store->save();

        $storefront = $this->builderService->synthesizeStorefront($store->fresh('merchant'));
        $storefront = $this->productService->extractEmbeddedProducts($store, $storefront);
        $generationId = (string) Str::uuid();

        $store->storefront_generation_id = $generationId;
        $this->publishService->persistDraft($store, $storefront);

        $session->store_id = $store->id;
        $session->storefront_snapshot = $storefront;
        $session->status = 'content_generated';
        $session->save();

        $mergedStorefront = $this->productService->mergeIntoStorefront($storefront, $store, activeOnly: true);

        return [
            'name' => 'generate_draft',
            'ok' => true,
            'generation_id' => $generationId,
            'template_id' => $session->selected_template_id,
            'storefront' => $mergedStorefront,
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
            if ($existing->merchant) {
                $existing->merchant->ensureActive();
            }

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
                'industry' => $industry,
                'status' => 'active',
                'activated_at' => now(),
                'subscription_plan' => config('dodopayments.default_plan', 'free'),
                'subscription_status' => 'active',
            ],
        );

        $merchant->fill([
            'business_name' => $businessName,
            'industry' => $industry,
        ])->save();

        $merchant->ensureActive();

        $slug = $this->uniqueStoreSlug($businessName);
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');

        $store = Store::create([
            'merchant_id' => $merchant->id,
            'name' => $businessName,
            'slug' => $slug,
            'status' => 'draft',
            'primary_domain' => "{$slug}.{$platformDomain}",
            'description' => (string) ($profile['description'] ?? ''),
            'brand_color' => $brandColor,
            'logo_url' => null,
            'contact_email' => $user->email,
            'business_location' => $profile['business_location'] ?? null,
            'weekly_orders' => $profile['weekly_orders'] ?? null,
            'payment_currencies' => $profile['payment_currencies'] ?? [],
            'staff_count' => $profile['staff_count'] ?? null,
            'physical_store_count' => $profile['physical_store_count'] ?? null,
            'storefront_template_id' => StorefrontTemplate::DEFAULT_ID,
        ])->load('merchant');

        $this->invalidateAdminApiCache();

        return $store;
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

    /**
     * @param  array<string, mixed>  $profile
     */
    private function syncStoreFromProfile(Store $store, array $profile): void
    {
        if ($profile === []) {
            return;
        }

        // Prefer existing store identity. Only fill blanks from the session profile
        // so builder generate turns cannot undo Settings → Store details edits.
        $nextName = filled($store->name)
            ? $store->name
            : ($profile['business_name'] ?? $store->name);

        $store->fill([
            'name' => $nextName,
            'description' => $profile['description'] ?? $store->description,
            'brand_color' => $profile['brand_color'] ?? $store->brand_color,
            'business_location' => $profile['business_location'] ?? $store->business_location,
            'weekly_orders' => $profile['weekly_orders'] ?? $store->weekly_orders,
            'payment_currencies' => $profile['payment_currencies'] ?? $store->payment_currencies,
            'staff_count' => $profile['staff_count'] ?? $store->staff_count,
            'physical_store_count' => $profile['physical_store_count'] ?? $store->physical_store_count,
        ])->save();

        $merchant = $store->merchant;
        if (! $merchant) {
            return;
        }

        $merchantUpdates = [];
        if (! filled($merchant->business_name) && filled($profile['business_name'] ?? null)) {
            $merchantUpdates['business_name'] = $profile['business_name'];
        } elseif (filled($nextName)) {
            $merchantUpdates['business_name'] = $nextName;
        }
        if (! filled($merchant->industry) && filled($profile['industry'] ?? null)) {
            $merchantUpdates['industry'] = $profile['industry'];
        }

        if ($merchantUpdates !== []) {
            $merchant->fill($merchantUpdates)->save();
        }
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
        $context = $this->sessionContext($session);
        $mergedPayload = array_merge([
            'suggested_actions' => $this->builderService->suggestedActionsFor($context),
            'color_options' => $this->buildColorOptions($context, $payload),
        ], $payload ?? []);

        StorefrontBuilderMessage::create([
            'session_id' => $session->id,
            'role' => 'assistant',
            'content' => $content,
            'payload' => $mergedPayload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $payload
     * @return list<string>
     */
    private function buildColorOptions(array $context, ?array $payload = null): array
    {
        $options = $this->builderService->colorPresetHexValues($context['industry'] ?? null);
        $applied = $payload['brand_color'] ?? $context['brand_color'] ?? null;

        if (is_string($applied) && preg_match('/^#[0-9A-Fa-f]{6}$/', $applied)) {
            $normalized = strtoupper($applied);
            $existing = array_map('strtoupper', $options);
            if (! in_array($normalized, $existing, true)) {
                array_unshift($options, $applied);
            }
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyVisualBuilderUpdates(StorefrontBuilderSession $session, array $data): bool
    {
        $store = $session->store;
        if (! $store) {
            return false;
        }

        $baseStorefront = $session->storefront_snapshot
            ?? $this->publishService->resolveDraft($store)
            ?? null;

        if (! is_array($baseStorefront)) {
            if (! empty($data['brand_color'])) {
                $store->brand_color = $data['brand_color'];
                $store->save();
                $profile = $session->business_profile ?? [];
                $profile['brand_color'] = $data['brand_color'];
                $session->business_profile = $profile;
                $session->save();

                $this->appendAssistantMessage(
                    $session,
                    'Got it — I saved '.$data['brand_color'].' as your brand color. Say “build my website” when you are ready.',
                    [
                        'type' => 'brand_color_applied',
                        'brand_color' => $data['brand_color'],
                    ],
                );

                return true;
            }

            if (array_key_exists('logo_url', $data)) {
                $logoUrl = is_string($data['logo_url']) && trim($data['logo_url']) !== ''
                    ? trim($data['logo_url'])
                    : null;
                $store->logo_url = $logoUrl;
                $store->save();

                $this->appendAssistantMessage(
                    $session,
                    $logoUrl
                        ? 'Done — I saved your logo. It will appear in your site header once your website is built.'
                        : 'Done — I removed your logo. Your business name will show in the header instead.',
                    [
                        'type' => 'logo_applied',
                        'logo_url' => $logoUrl,
                    ],
                );

                return true;
            }

            return false;
        }

        $storefront = json_decode(json_encode($baseStorefront), true);
        if (! is_array($storefront)) {
            return false;
        }

        $changedPaths = [];
        $summary = null;
        $payloadType = 'website_refined';

        if (! empty($data['brand_color'])) {
            $result = $this->builderService->applyBrandColor(
                $storefront,
                $store,
                $data['brand_color'],
                is_array($data['palette'] ?? null) ? $data['palette'] : null,
            );
            $storefront = $result['storefront'];
            $changedPaths = array_merge($changedPaths, $result['changed_paths']);
            $profile = $session->business_profile ?? [];
            $profile['brand_color'] = $data['brand_color'];
            $session->business_profile = $profile;
            $label = is_string($data['color_label'] ?? null) && trim($data['color_label']) !== ''
                ? trim($data['color_label'])
                : null;
            $summary = $label
                ? "Done — I updated your color palette ({$label}). Check the preview on the right."
                : 'Done — I updated your color palette. Check the preview on the right.';
            $payloadType = 'brand_color_applied';
        }

        if (! empty($data['media_updates']) && is_array($data['media_updates'])) {
            $result = $this->builderService->applyMediaUpdates($storefront, $data['media_updates']);
            $storefront = $result['storefront'];
            $changedPaths = array_merge($changedPaths, $result['changed_paths']);
            $summary = $summary ?? $this->builderService->describeStorefrontEdit($result['changed_paths']);
        }

        if (! empty($data['apply_stock_images'])) {
            $result = $this->builderService->applyStockImages($storefront, $store);
            $storefront = $result['storefront'];
            $changedPaths = array_merge($changedPaths, $result['changed_paths']);
            $summary = 'Done — I added suitable photos to your website. Check the preview on the right.';
        }

        if (array_key_exists('logo_url', $data)) {
            $logoUrl = is_string($data['logo_url']) && trim($data['logo_url']) !== ''
                ? trim($data['logo_url'])
                : null;
            $store->logo_url = $logoUrl;
            $store->save();
            $summary = $logoUrl
                ? 'Done — I updated your logo. Check the preview on the right.'
                : 'Done — I removed your logo. Your business name will show in the header instead.';
            $payloadType = 'logo_applied';
        }

        $changedPaths = array_values(array_unique($changedPaths));
        if ($changedPaths === [] && empty($data['brand_color']) && ! array_key_exists('logo_url', $data)) {
            return false;
        }

        unset($storefront['products']);
        $this->publishService->persistDraft($store, $storefront);

        $session->storefront_snapshot = $storefront;
        $session->status = 'review_ready';
        $session->save();

        $this->appendAssistantMessage(
            $session,
            $summary ?? $this->builderService->describeStorefrontEdit($changedPaths),
            [
                'type' => $payloadType,
                'changed_paths' => $changedPaths,
                'brand_color' => $data['brand_color'] ?? $store->brand_color,
                ...(array_key_exists('logo_url', $data) ? ['logo_url' => $store->logo_url] : []),
            ],
        );

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionContext(StorefrontBuilderSession $session, ?string $currentMessage = null): array
    {
        $profile = $session->business_profile ?? [];

        return [
            'status' => $session->status,
            'business_name' => $profile['business_name'] ?? $session->store?->name,
            'industry' => $profile['industry'] ?? $session->store?->merchant?->industry,
            'brand_color' => $profile['brand_color'] ?? $session->store?->brand_color,
            'has_store' => (bool) $session->store_id,
            'selected_template_id' => $session->selected_template_id,
            'has_storefront_draft' => ! empty($session->storefront_snapshot),
            'last_intent' => $session->last_intent,
            'recent_messages' => $this->builderService->recentConversationHistory($session, $currentMessage),
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

    private function templateLabel(string $templateId): string
    {
        $template = StorefrontTemplate::query()->find($templateId);

        if ($template?->label) {
            return $template->label;
        }

        return str_replace('_', ' ', $templateId);
    }

    private function formatSessionPayload(StorefrontBuilderSession $session): array
    {
        $profile = $session->business_profile ?? [];
        $recommendations = $session->status !== 'collecting_requirements'
            ? $this->recommendTemplatesForProfile($profile)
            : [];

        $store = $session->store;

        $storefrontSnapshot = $session->storefront_snapshot;

        if (is_array($storefrontSnapshot) && ! empty($storefrontSnapshot['custom_files'])) {
            $this->projectStorage->extractAndPersist((int) $session->id, $storefrontSnapshot);
            $session->storefront_snapshot = $storefrontSnapshot;
            $this->publishService->reconnectAndSaveModel($session);
        }

        // Ask AI / refine tools need a draft. If the session snapshot is empty but the
        // store already has website draft JSON, hydrate so Next.js draft tools unlock.
        if ((! is_array($storefrontSnapshot) || $storefrontSnapshot === []) && $store) {
            $editorDraft = $this->publishService->resolveEditorDraft($store);
            if (is_array($editorDraft) && $editorDraft !== []) {
                $storefrontSnapshot = $editorDraft;
                $session->storefront_snapshot = $editorDraft;
                if (in_array($session->status, ['collecting_requirements', 'template_recommendation'], true)) {
                    $session->status = 'content_generated';
                }
                $this->publishService->reconnectAndSaveModel($session);
            }
        }

        $storefrontSnapshot = $this->projectStorage->hydrateSnapshot(
            is_array($storefrontSnapshot) ? $storefrontSnapshot : null,
            (int) $session->id,
            migrateInline: false,
        );

        return [
            'session' => [
                'id' => (string) $session->id,
                'status' => $session->status,
                'business_profile' => $profile,
                'selected_template_id' => $session->selected_template_id,
                'storefront_snapshot' => $store && is_array($storefrontSnapshot)
                    ? $this->productService->mergeIntoStorefront($storefrontSnapshot, $store, activeOnly: true)
                    : $storefrontSnapshot,
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
        $platformDomain = config('storehause.platform_domain', 'bizgrid.shop');
        $subdomainHost = "{$store->slug}.{$platformDomain}";

        return [
            'id' => (string) $store->id,
            'slug' => $store->slug,
            'business_name' => $store->name,
            'industry' => $store->merchant?->industry ?? 'other',
            'description' => $store->description ?? '',
            'brand_color' => $store->brand_color ?? '#0E7C66',
            'logo_url' => $store->logo_url,
            'contact_email' => $store->contact_email ?? $store->merchant?->owner?->email,
            'contact_phone' => $store->contact_phone,
            'storefront_template_id' => $store->storefront_template_id ?? StorefrontTemplate::DEFAULT_ID,
            'subdomain' => $store->slug,
            'subdomain_host' => $subdomainHost,
            'primary_domain' => $store->primary_domain ?? $subdomainHost,
            ...$this->publishService->publishMeta($store),
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

    private function enforceAiUsage(Store $store): void
    {
        $store->loadMissing('merchant');
        if (! $store->merchant) {
            return;
        }

        $this->enforcement->assertCanUseAi($store->merchant);
        $this->enforcement->consumeAiCredit($store->merchant);
    }

    private function bindExecutionLogContext(
        Request $request,
        StorefrontBuilderSession $session,
        Store $store,
    ): void {
        $store->loadMissing('merchant');

        $this->executionLogs->setContext([
            'user_id' => $request->user()?->id,
            'merchant_id' => $store->merchant_id,
            'store_id' => $store->id,
            'builder_session_id' => $session->id,
        ]);
    }

    private function invalidateBuilderApiCache(StorefrontBuilderSession $session): void
    {
        $this->invalidateUserApiCache((int) $session->user_id);

        if ($session->store) {
            $this->invalidateStoreApiCache($session->store);
        }
    }
}
