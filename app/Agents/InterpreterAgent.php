<?php

namespace App\Agents;

use App\Services\PromptService;

class InterpreterAgent extends BaseAgent
{
    private const INDUSTRIES = [
        'food_and_beverage',
        'fashion_and_apparel',
        'beauty_and_skincare',
        'electronics',
        'home_and_living',
        'services',
        'other',
    ];

    public function __construct(
        private readonly PromptService $prompts,
    ) {}

    public function name(): string
    {
        return 'interpreter';
    }

    public function temperature(): float
    {
        return 0.2;
    }

    public function systemPrompt(): string
    {
        return $this->prompts->load($this->name(), $this->promptVersion());
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_name' => ['type' => ['string', 'null']],
                'description' => ['type' => ['string', 'null']],
                'industry' => [
                    'type' => ['string', 'null'],
                    'enum' => array_merge(self::INDUSTRIES, [null]),
                ],
                'brand_color' => ['type' => ['string', 'null']],
                'tone' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['business_name', 'description', 'industry', 'brand_color', 'tone'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function execute(array $context): ?array
    {
        $message = $context['message'] ?? '';
        $currentProfile = $context['current_profile'] ?? [];
        $conversationHistory = $context['conversation_history'] ?? [];

        $result = $this->chatStructured([
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userMessage([
                    'current_profile' => $currentProfile,
                    'message' => $message,
                    'recent_messages' => array_slice(
                        $conversationHistory,
                        -20,
                    ),
                ]),
            ],
        ], $this->outputSchema());

        if (! is_array($result)) {
            return null;
        }

        return $this->sanitizeProfile(
            $this->mergeProfile($currentProfile, $result),
        );
    }

    /**
     * @param  array<string, mixed>  $currentProfile
     * @param  array<string, mixed>  $extracted
     * @return array<string, mixed>
     */
    private function mergeProfile(array $currentProfile, array $extracted): array
    {
        $merged = $currentProfile;

        foreach ($extracted as $key => $value) {
            if ($key === 'tone') {
                if (is_array($value) && $value !== []) {
                    $merged['tone'] = $value;
                }

                continue;
            }

            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function sanitizeProfile(array $profile): array
    {
        $industry = $profile['industry'] ?? null;
        if (! is_string($industry) || ! in_array($industry, self::INDUSTRIES, true)) {
            $profile['industry'] = null;
        }

        if (isset($profile['brand_color']) && (! is_string($profile['brand_color']) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $profile['brand_color']))) {
            $profile['brand_color'] = null;
        }

        $tone = is_array($profile['tone'] ?? null) ? $profile['tone'] : [];
        $profile['tone'] = array_values(array_unique(array_filter(
            array_map('strval', $tone),
            fn (string $t): bool => trim($t) !== '',
        )));

        foreach (['business_name', 'description'] as $key) {
            if (isset($profile[$key]) && is_string($profile[$key])) {
                $profile[$key] = trim($profile[$key]) ?: null;
            }
        }

        return $profile;
    }
}
