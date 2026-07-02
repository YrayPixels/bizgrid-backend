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
use App\Services\StorefrontPublishService;
use App\Services\StoreProductService;
use App\Support\SseStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StorefrontBuilderController extends Controller
{
    public function __construct(
        private readonly StorefrontBuilderService $builderService,
        private readonly StoreProductService $productService,
        private readonly StorefrontPublishService $publishService,
    ) {}

    public function startSession(Request $request): JsonResponse
    {
        $user = $request->user();
        $session = $this->findOrCreateActiveSession($user);

        if ($session->messages()->count() === 0) {
            $this->appendAssistantMessage(
                $session,
                'Hi! Tell me about your business — what you sell, who it\'s for, and the vibe you want. I\'ll design and build your website.',
                ['type' => 'welcome'],
            );
        }

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
            'message' => 'required|string|max:2000',
            'business_profile' => 'nullable|array',
            'status' => 'nullable|string|in:collecting_requirements,template_recommendation,content_generated,products_pending,review_ready,published',
            'assistant_message' => 'nullable|string|max:4000',
            'assistant_payload' => 'nullable|array',
            'selected_template_id' => ['nullable', 'string', Rule::in(StorefrontTemplate::activeConcreteIds())],
            'storefront_snapshot' => 'nullable|array',
            'brand_color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'color_label' => 'nullable|string|max:120',
            'media_updates' => 'nullable|array',
            'apply_stock_images' => 'nullable|boolean',
        ]);

        $session = $this->findOwnedSession($request, $sessionId);
        $this->appendUserMessage($session, $data['message']);

        if ($this->applyVisualBuilderUpdates($session, $data)) {
            return response()->json([
                ...$this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])),
                'storefront' => $session->store
                    ? $this->productService->mergeIntoStorefront($session->storefront_snapshot ?? [], $session->store)
                    : $session->storefront_snapshot,
            ]);
        }

        if (! empty($data['assistant_message'])) {
            $this->persistClientTurn($session, $request->user(), $data);
        } else {
            try {
                $this->processUserMessage($session, $data['message']);
            } catch (StorefrontAiUnavailableException $exception) {
                return $this->aiUnavailableResponse($exception);
            }
        }

        return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
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

                return response()->json([
                    ...$this->formatSessionPayload($session),
                    'storefront' => $session->store
                        ? $this->productService->mergeIntoStorefront($session->storefront_snapshot ?? [], $session->store)
                        : $session->storefront_snapshot,
                ]);
            }
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

        return response()->json($this->formatSessionPayload($session->fresh(['messages', 'store.merchant'])));
    }

    public function generateDraft(Request $request, int $sessionId): JsonResponse
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

        $mergedStorefront = $this->productService->mergeIntoStorefront($storefront, $store);

        $this->appendAssistantMessage(
            $session,
            'Your website is ready. Preview it on the right, then tell me what to refine — headline, about section, CTA, or SEO.',
            [
                'type' => 'website_generated',
                'generation_id' => $generationId,
            ],
        );

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

                $mergedStorefront = $this->productService->mergeIntoStorefront($storefront, $store);

                $this->appendAssistantMessage(
                    $session,
                    'Your website is ready. Preview it on the right, then tell me what to refine — headline, about section, CTA, or SEO.',
                    [
                        'type' => 'website_generated',
                        'generation_id' => $generationId,
                    ],
                );

                SseStream::log($emit, 'builder', 'done', 'Website ready', 'Your storefront is live in preview.');

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
            'instruction' => 'required|string|max:2000',
            'storefront' => 'nullable|array',
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

        if (! empty($data['storefront'])) {
            $storefront = $data['storefront'];
            $changedPaths = $data['changed_paths'] ?? [];
            $summary = $data['assistant_message']
                ?? $this->builderService->describeStorefrontEdit($changedPaths);
        } else {
            try {
                $baseStorefront = $session->storefront_snapshot
                    ?? $this->publishService->resolveDraft($store)
                    ?? $this->builderService->synthesizeStorefront($store->load('merchant'));
                $result = $this->builderService->applyChatEdit($baseStorefront, $data['instruction'], $store);
                $storefront = $result['storefront'];
                $changedPaths = $result['changed_paths'];
                $summary = $result['assistant_message'] ?? $this->builderService->describeStorefrontEdit($changedPaths);
            } catch (StorefrontAiUnavailableException $exception) {
                return $this->aiUnavailableResponse($exception);
            }
        }

        unset($storefront['products']);
        $this->publishService->persistDraft($store, $storefront);

        $session->storefront_snapshot = $storefront;
        $session->status = 'review_ready';
        $session->save();

        $mergedStorefront = $this->productService->mergeIntoStorefront($storefront, $store);

        $this->appendAssistantMessage(
            $session,
            $summary,
            [
                'type' => 'website_refined',
                'changed_paths' => $changedPaths,
            ],
        );

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

        if (! empty($data['selected_template_id']) && $session->selected_template_id && $session->store) {
            $session->store->storefront_template_id = $session->selected_template_id;
            $session->store->save();
        }

        if (! empty($data['storefront_snapshot']) && is_array($data['storefront_snapshot']) && $session->store) {
            $generationId = (string) Str::uuid();
            $storefront = $this->productService->extractEmbeddedProducts(
                $session->store,
                $data['storefront_snapshot'],
            );
            if (! empty($data['selected_template_id']) && $session->selected_template_id) {
                data_set($storefront, 'template.id', $session->selected_template_id);
                data_set($storefront, 'template.source', 'merchant_selected');
            }

            $snapshotTemplateId = data_get($storefront, 'template.id');
            if (is_string($snapshotTemplateId) && $snapshotTemplateId !== '' && $snapshotTemplateId !== 'ai_pick') {
                $session->store->storefront_template_id = $snapshotTemplateId;
            }

            $session->storefront_snapshot = $storefront;
            $session->store->storefront_generation_id = $generationId;
            $this->publishService->persistDraft($session->store, $storefront);
            $session->status = 'content_generated';
        }

        \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'mysql'
            ? \Illuminate\Support\Facades\DB::reconnect()
            : null;
        $session->save();

        $this->appendAssistantMessage(
            $session,
            (string) $data['assistant_message'],
            is_array($data['assistant_payload'] ?? null) ? $data['assistant_payload'] : null,
        );
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
            'storefront_snapshot' => $store ? $this->publishService->resolveDraft($store) : null,
        ]);
    }

    private function processUserMessage(StorefrontBuilderSession $session, string $message): void
    {
        $context = $this->sessionContext($session, $message);

        if (! $this->builderService->isSubstantiveMessage($message)) {
            $this->appendAssistantMessage(
                $session,
                $this->builderService->conversationalReply($context, $message),
                ['type' => 'conversation'],
            );

            return;
        }

        $profile = $this->builderService->extractBusinessProfileFromMessage(
            $message,
            $session->business_profile ?? [],
            $context['recent_messages'] ?? [],
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

        $hasDraft = ! empty($session->storefront_snapshot);
        $recommendations = $hasMinimum || $hasDraft
            ? $this->recommendTemplatesForProfile($profile)
            : [];

        if ($hasDraft && $this->builderService->isStockImageIntent($message)) {
            $this->applyVisualBuilderUpdates($session, ['apply_stock_images' => true]);

            return;
        }

        if ($hasDraft && $this->builderService->isProductIntent($message)) {
            $this->appendAssistantMessage(
                $session,
                'Products live on your Products page — add names, prices, photos, and inventory there. They appear on your storefront automatically.',
                [
                    'type' => 'product_guidance',
                    'suggested_actions' => [
                        ['type' => 'link', 'label' => 'Go to Products', 'href' => '/admin/products'],
                        ['type' => 'prompt', 'label' => 'Suggest stock photos', 'message' => 'Add suitable stock photos to my website'],
                        ...$this->builderService->colorPresetActions($profile['industry'] ?? null, 2),
                    ],
                ],
            );

            return;
        }

        if ($hasDraft && $this->builderService->isColorIntent($message)) {
            $resolved = $this->builderService->resolveBrandColorFromMessage(
                $message,
                $profile,
                $session->store,
            );
            if ($resolved && $this->applyVisualBuilderUpdates($session, [
                'brand_color' => $resolved['brand_color'],
                'color_label' => $resolved['label'],
                'palette' => $resolved['palette'] ?? null,
            ])) {
                return;
            }
        }

        if ($hasDraft && $this->builderService->isBuildIntent($message)) {
            $designDirection = $this->builderService->resolveDesignDirectionFromMessage($message, $profile, $session->store);
            $paletteOptions = [];

            if (is_array($designDirection)) {
                $session->selected_template_id = $designDirection['template_id'];
                $profile['brand_color'] = $designDirection['brand_color'];

                if (! empty($designDirection['industry'])) {
                    $profile['industry'] = $designDirection['industry'];
                }

                if (! empty($designDirection['tone'])) {
                    $profile['tone'] = array_values(array_unique(array_merge($profile['tone'] ?? [], $designDirection['tone'])));
                }

                $session->business_profile = $profile;

                if ($session->store) {
                    $session->store->storefront_template_id = $designDirection['template_id'];
                    $session->store->brand_color = $designDirection['brand_color'];
                    $session->store->save();
                    $session->store->merchant?->fill([
                        'industry' => $profile['industry'] ?? $session->store->merchant?->industry,
                    ])->save();
                }

                $paletteOptions = array_values(array_map(
                    fn (array $entry): string => $entry['color'],
                    $designDirection['palette'] ?? [],
                ));
            } else {
                $templateId = $this->builderService->resolveTemplateFromMessage($message);
                if ($templateId) {
                    $session->selected_template_id = $templateId;
                    if ($session->store) {
                        $session->store->storefront_template_id = $templateId;
                        $session->store->save();
                    }
                }
            }

            $result = $this->executeGenerateDraftTool($session, $recommendations);
            if ($result['ok'] ?? false) {
                if (is_array($designDirection)) {
                    $rebuildMessage = sprintf(
                        'Done — I refreshed your website with %s. Your primary color is %s. Check the preview on the right, then tell me what to refine.',
                        rtrim($designDirection['merchant_summary'], '.'),
                        strtolower($designDirection['color_label']),
                    );
                    $payload = [
                        'type' => 'website_generated',
                        'generation_id' => $result['generation_id'] ?? null,
                        'design_direction' => $designDirection,
                        'color_options' => $paletteOptions,
                    ];
                } else {
                    $resolvedTemplateId = $session->selected_template_id ?? 'new';
                    $templateLabel = is_string($resolvedTemplateId)
                        ? $this->templateLabel($resolvedTemplateId)
                        : 'new';
                    $rebuildMessage = $this->builderService->isDesignChangeIntent($message) || $this->builderService->isRebuildIntent($message)
                        ? "Done — I refreshed your website with a {$templateLabel} look. Check the preview on the right, then tell me what to refine."
                        : 'Your website is ready. Preview it on the right, then tell me what to refine — headline, about section, CTA, or SEO.';
                    $payload = [
                        'type' => 'website_generated',
                        'generation_id' => $result['generation_id'] ?? null,
                    ];
                }

                $this->appendAssistantMessage($session, $rebuildMessage, $payload);
            }

            return;
        }

        if ($hasDraft && $this->builderService->isEditIntent($message)) {
            $this->applyChatEditFromMessage($session, $message);

            return;
        }

        if ($hasDraft) {
            $this->appendAssistantMessage(
                $session,
                $this->builderService->conversationalReply($context, $message),
                ['type' => 'conversation'],
            );

            return;
        }

        if ($this->builderService->isBuildIntent($message) && $hasMinimum) {
            $result = $this->executeGenerateDraftTool($session, $recommendations);
            if ($result['ok'] ?? false) {
                $this->appendAssistantMessage(
                    $session,
                    'Your website is ready. Preview it on the right, then tell me what to refine — headline, about section, CTA, or SEO.',
                    [
                        'type' => 'website_generated',
                        'generation_id' => $result['generation_id'] ?? null,
                    ],
                );

                return;
            }
        }

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

        try {
            $agentTurn = $this->builderService->planBuilderTurn(
                $message,
                $context,
                $profile,
                $recommendations,
            );
        } catch (StorefrontAiUnavailableException) {
            $this->handleBuilderTurnWithoutAi($session, $message, $profile, $recommendations);

            return;
        }

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

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<array<string, mixed>>  $recommendations
     */
    private function handleBuilderTurnWithoutAi(
        StorefrontBuilderSession $session,
        string $message,
        array $profile,
        array $recommendations,
    ): void {
        if ($this->builderService->isBuildIntent($message)) {
            $result = $this->executeGenerateDraftTool($session, $recommendations);
            if ($result['ok'] ?? false) {
                $this->appendAssistantMessage(
                    $session,
                    'Your website is ready. Preview it on the right, then tell me what to refine — headline, about section, CTA, or SEO.',
                    [
                        'type' => 'website_generated',
                        'generation_id' => $result['generation_id'] ?? null,
                    ],
                );

                return;
            }
        }

        $businessName = $profile['business_name'] ?? 'your business';
        $templateLabel = $recommendations[0]['label'] ?? 'website';

        $this->appendAssistantMessage(
            $session,
            "Great — I can build a {$templateLabel} style storefront for {$businessName}. Say “build my website” when you’re ready.",
            [
                'type' => 'agent_turn',
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

        $mergedStorefront = $this->productService->mergeIntoStorefront($storefront, $store);

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

    /**
     * @param  array<string, mixed>  $profile
     */
    private function syncStoreFromProfile(Store $store, array $profile): void
    {
        if ($profile === []) {
            return;
        }

        $store->fill([
            'name' => $profile['business_name'] ?? $store->name,
            'description' => $profile['description'] ?? $store->description,
            'brand_color' => $profile['brand_color'] ?? $store->brand_color,
        ])->save();

        $store->merchant?->fill([
            'business_name' => $profile['business_name'] ?? $store->merchant?->business_name,
            'industry' => $profile['industry'] ?? $store->merchant?->industry,
        ])->save();
    }

    private function applyChatEditFromMessage(StorefrontBuilderSession $session, string $instruction): void
    {
        $store = $session->store;
        if (! $store) {
            $this->appendAssistantMessage(
                $session,
                'Generate a website before applying chat edits.',
                ['type' => 'conversation'],
            );

            return;
        }

        $baseStorefront = $session->storefront_snapshot
            ?? $this->publishService->resolveDraft($store)
            ?? $this->builderService->synthesizeStorefront($store->load('merchant'));
        $result = $this->builderService->applyChatEdit($baseStorefront, $instruction, $session->store);
        $storefront = $result['storefront'];
        $changedPaths = $result['changed_paths'];
        $summary = $result['assistant_message'] ?? $this->builderService->describeStorefrontEdit($changedPaths);

        unset($storefront['products']);
        $this->publishService->persistDraft($store, $storefront);

        $session->storefront_snapshot = $storefront;
        $session->status = 'review_ready';
        $session->save();

        $this->appendAssistantMessage(
            $session,
            $summary,
            [
                'type' => 'website_refined',
                'changed_paths' => $changedPaths,
            ],
        );
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

        $changedPaths = array_values(array_unique($changedPaths));
        if ($changedPaths === [] && empty($data['brand_color'])) {
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

        return [
            'session' => [
                'id' => (string) $session->id,
                'status' => $session->status,
                'business_profile' => $profile,
                'selected_template_id' => $session->selected_template_id,
                'storefront_snapshot' => $store && is_array($session->storefront_snapshot)
                    ? $this->productService->mergeIntoStorefront($session->storefront_snapshot, $store)
                    : $session->storefront_snapshot,
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
            'contact_email' => $store->contact_email ?? $store->merchant?->email,
            'contact_phone' => $store->contact_phone,
            'storefront_template_id' => $store->storefront_template_id ?? 'ai_pick',
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
}
