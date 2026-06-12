<?php

namespace App\Http\Controllers;

use App\Models\StorefrontTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StorefrontTemplateController extends Controller
{
    public function active(): JsonResponse
    {
        $templates = StorefrontTemplate::query()
            ->where('is_active', true)
            ->whereIn('id', StorefrontTemplate::defaultActiveConcreteIds())
            ->orderBy('sort_order')
            ->get()
            ->map(fn (StorefrontTemplate $template) => $template->toCatalogArray())
            ->values();

        return response()->json([
            'templates' => $templates,
        ]);
    }

    public function recommend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt' => 'nullable|string|max:2000',
            'industry' => 'nullable|string|max:80',
            'tone' => 'nullable|array|max:12',
            'tone.*' => 'string|max:40',
            'limit' => 'nullable|integer|min:1|max:4',
        ]);

        $prompt = Str::lower(trim(($data['prompt'] ?? '').' '.implode(' ', $data['tone'] ?? [])));
        $industry = $data['industry'] ?? null;
        $limit = $data['limit'] ?? 4;

        $recommendations = StorefrontTemplate::query()
            ->where('is_active', true)
            ->whereIn('id', StorefrontTemplate::activeConcreteIds())
            ->orderBy('sort_order')
            ->get()
            ->map(function (StorefrontTemplate $template) use ($industry, $prompt): array {
                $score = 0.35;
                $reasons = [];

                if ($industry && in_array($industry, $template->industries ?? [], true)) {
                    $score += 0.35;
                    $reasons[] = 'strong '.str_replace('_', ' ', $industry).' fit';
                }

                $matchedToneTags = collect($template->tone_tags ?? [])
                    ->filter(fn (string $tag): bool => $prompt !== '' && Str::contains($prompt, Str::lower($tag)))
                    ->values()
                    ->all();

                if ($matchedToneTags) {
                    $score += min(0.18, count($matchedToneTags) * 0.06);
                    $reasons[] = 'matches '.implode(' and ', array_slice($matchedToneTags, 0, 2)).' tone';
                }

                $searchText = Str::lower(implode(' ', array_merge(
                    [$template->label, $template->description, $template->best_for],
                    $template->visual_tags ?? [],
                    $template->product_types ?? [],
                )));

                $keywordMatches = collect(preg_split('/\W+/', $prompt) ?: [])
                    ->filter(fn (string $word): bool => strlen($word) > 3 && Str::contains($searchText, $word))
                    ->count();

                if ($keywordMatches > 0) {
                    $score += min(0.12, $keywordMatches * 0.03);
                }

                return [
                    'template_id' => $template->id,
                    'score' => round(min($score, 0.98), 2),
                    'reason' => $reasons
                        ? 'Recommended because it has '.implode(' and ', $reasons).'.'
                        : 'Recommended as a flexible starting point for '.Str::lower($template->best_for ?: 'this storefront').'.',
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return response()->json([
            'recommendations' => $recommendations,
        ]);
    }
}
