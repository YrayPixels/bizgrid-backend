<?php

namespace App\Support;

use Illuminate\Support\Str;

class ShoppingQueryHeuristics
{
    public static function isCatalogOverviewQuestion(string $message): bool
    {
        $text = Str::lower(trim($message));
        if ($text === '' || strlen($text) > 180) {
            return false;
        }

        if (preg_match('/\bwhat(\s+all)?\s+(does|do)\s+(the\s+)?(store|shop|you)\s+(sell|stock|carry|have|offer)\b/u', $text)) {
            return true;
        }

        if (preg_match('/\bwhat\s+(does|do)\s+(you|this\s+store)\s+(sell|stock|carry|have|offer)\b/u', $text)) {
            return true;
        }

        if (preg_match('/\b(what|which)\s+(products?|items?|categories)\s+(do\s+you\s+)?(have|sell|carry|stock)\b/u', $text)) {
            return true;
        }

        if (preg_match('/\bwhat\s+.*\b(available|in\s+(the\s+)?store)\b/u', $text)) {
            return true;
        }

        return preg_match('/\b(show|list)\s+(me\s+)?(everything|all\s+products?|the\s+catalog|what\s+you\s+sell)\b/u', $text) === 1;
    }

    public static function isGreeting(string $message): bool
    {
        $text = Str::lower(trim($message));

        return $text !== '' && strlen($text) <= 80
            && preg_match('/^(hi|hello|hey|thanks|thank you|good (morning|afternoon|evening))[\s!.?]*$/u', $text) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fallbackPlan(string $message, array $shopper): ?array
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        if (self::isGreeting($message)) {
            return [
                'interpretation' => [
                    'task_summary' => 'Greet the shopper and invite them to browse.',
                    'steps' => ['Reply warmly and mention what this store sells.'],
                    'constraints' => ['greeting'],
                ],
                'plan' => [
                    'action' => 'greeting',
                    'intent_summary' => 'Welcome the shopper.',
                    'plan_steps' => [],
                ],
                'intent' => [
                    'reply' => $shopper['welcome_message'] ?? 'What can I help you find today?',
                    'needs_clarification' => false,
                    'product_query' => null,
                    'categories' => [],
                ],
            ];
        }

        if (self::isCatalogOverviewQuestion($message)) {
            $categoryNames = array_values(array_filter(array_map(
                fn ($category) => is_array($category) ? (string) ($category['name'] ?? '') : '',
                $shopper['categories'] ?? [],
            )));

            return [
                'interpretation' => [
                    'task_summary' => 'Summarize what this store sells and show representative products.',
                    'steps' => [
                        'List top categories from the store catalog.',
                        'Show a few example products across categories.',
                    ],
                    'constraints' => ['catalog_overview', 'no_product_query'],
                ],
                'plan' => [
                    'action' => 'catalog_overview',
                    'intent_summary' => 'Show store assortment overview.',
                    'plan_steps' => [
                        ['step' => 1, 'description' => 'Summarize categories and sample products.', 'tool' => 'catalog_overview'],
                    ],
                ],
                'intent' => [
                    'reply' => self::catalogOverviewReply($shopper, $categoryNames),
                    'needs_clarification' => false,
                    'product_query' => null,
                    'categories' => array_slice($categoryNames, 0, 6),
                ],
            ];
        }

        return null;
    }

    /**
     * @param  list<string>  $categoryNames
     */
    public static function catalogOverviewReply(array $shopper, array $categoryNames): string
    {
        $storeName = (string) ($shopper['store_name'] ?? 'this store');
        $names = array_values(array_filter($categoryNames));

        if ($names === []) {
            return "Here’s a quick look at what {$storeName} carries — browse categories or tell me what you’re shopping for.";
        }

        $preview = implode(', ', array_slice($names, 0, 5));
        if (count($names) > 5) {
            $preview .= ', and more';
        }

        return "{$storeName} carries {$preview}. Here are some picks to get you started — tell me your budget or use case if you want something more specific.";
    }
}
