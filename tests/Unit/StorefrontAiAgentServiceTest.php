<?php

use App\Services\StorefrontAiAgentService;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('parses native OpenAI tool calls from planBuilderTurn', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I will generate your storefront draft now.',
                        'tool_calls' => [
                            [
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'select_template',
                                    'arguments' => json_encode([
                                        'template_id' => 'cosmetics',
                                        'source' => 'ai_selected',
                                    ]),
                                ],
                            ],
                            [
                                'id' => 'call_2',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'generate_draft',
                                    'arguments' => '{}',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $service = app(StorefrontAiAgentService::class);

    $result = $service->planBuilderTurn(
        'Go ahead and generate the draft',
        ['status' => 'template_recommendation', 'has_store' => true],
        [
            'business_name' => 'Glow Rituals',
            'description' => 'Organic skincare for busy professionals.',
            'industry' => 'beauty_and_skincare',
        ],
        [
            ['template_id' => 'cosmetics', 'score' => 0.9],
        ],
        ['cosmetics', 'beauty', 'minimalistic'],
    );

    expect($result)->not->toBeNull()
        ->and($result['assistant_message'])->toBe('I will generate your storefront draft now.')
        ->and($result['tool_calls'])->toHaveCount(2)
        ->and($result['tool_calls'][0])->toMatchArray([
            'name' => 'select_template',
            'arguments' => [
                'template_id' => 'cosmetics',
                'source' => 'ai_selected',
            ],
        ])
        ->and($result['tool_calls'][1])->toMatchArray([
            'name' => 'generate_draft',
            'arguments' => [],
        ]);

    Http::assertSent(function ($request) {
        $payload = $request->data();

        return $request->url() === 'https://api.openai.com/v1/chat/completions'
            && isset($payload['tools'])
            && collect($payload['tools'])->pluck('function.name')->all() === [
                'recommend_templates',
                'select_template',
                'generate_draft',
                'ask_clarifying_question',
            ]
            && ($payload['tool_choice'] ?? null) === 'auto'
            && ! array_key_exists('response_format', $payload);
    });
});
