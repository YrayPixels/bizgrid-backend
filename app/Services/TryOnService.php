<?php

namespace App\Services;

use App\Jobs\PollTryOnSessionStatus;
use App\Models\Customer;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\TryOnSession;
use App\Services\PerfectCorp\PerfectCorpClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class TryOnService
{
    public const MODES = [
        'bag',
        'clothes',
        'hat',
        'shoes',
        'nail',
        'watch',
        'necklace',
        'fabric',
    ];

    public const GENDER_STYLE_MODES = ['bag', 'hat', 'shoes'];

    public const BAG_STYLES = [
        'random',
        'style_parisian_chic',
        'style_urban_chic',
        'style_mediterranean_chic',
        'style_art_deco_style',
    ];

    public const HAT_STYLES = [
        'random',
        'style_sporty_casual',
        'style_urban_fashion',
        'style_vacation_casual',
        'style_warm_cozy',
        'style_bohemian',
    ];

    public const SHOES_STYLES = [
        'random',
        'style_minimalist',
        'style_bohemian',
        'style_cottagecore',
        'style_french_elegance',
        'style_retro_fashion',
    ];

    public const GARMENT_CATEGORIES = [
        'auto',
        'full_body',
        'upper_body',
        'lower_body',
        'outerwear',
        'shoes',
    ];

    public const NAIL_FINGERS = ['thumb', 'index', 'middle', 'ring', 'pinky'];

    public const NAIL_EFFECT_TYPES = ['nail_polish', 'press_on_nails'];

    public const NAIL_SUB_TYPES = ['color', 'design'];

    public const NAIL_POLISH_TEXTURES = [
        'matte', 'cream', 'metallic', 'jelly', 'sheer', 'pearl',
        'textured', 'shimmer_coarse', 'shimmer_fine',
    ];

    public const NAIL_PRESS_ON_TEXTURES = ['matte', 'cream', 'metallic'];

    public const NAIL_SHAPES = [
        'square_oval', 'square_square', 'square_squoval',
        'squoval_oval', 'squoval_square', 'squoval_squoval',
        'oval_oval', 'oval_square', 'oval_squoval',
        'almond_oval', 'almond_square', 'almond_squoval',
        'stiletto_oval', 'stiletto_square', 'stiletto_squoval',
    ];

    /** @return list<array{id: string, name: string, gender: string, image_url: string}> */
    public function catalogModels(): array
    {
        return [
            [
                'id' => 'model_amara',
                'name' => 'Amara',
                'gender' => 'female',
                'image_url' => 'https://plugins-media.makeupar.com/smb/blog/post/2024-05-07/b103976d-1b0e-4bed-aab4-9307308b84d7.jpg',
            ],
            [
                'id' => 'model_leila',
                'name' => 'Leila',
                'gender' => 'female',
                'image_url' => 'https://bcw-media.s3.ap-northeast-1.amazonaws.com/strapi/assets/fca6a904_b13a_4c90_bc52_d9200a473c70_4d994afa3e.jpg',
            ],
            [
                'id' => 'model_zuri',
                'name' => 'Zuri',
                'gender' => 'female',
                'image_url' => 'https://bcw-media.s3.ap-northeast-1.amazonaws.com/strapi/assets/5f42385b_6aef_44cd_b576_2ec10e31305d_824cc2019b.jpg',
            ],
            [
                'id' => 'model_kofi',
                'name' => 'Kofi',
                'gender' => 'male',
                'image_url' => 'https://bcw-media.s3.ap-northeast-1.amazonaws.com/strapi/assets/cc55fe0d_aec9_4ead_b2e9_bc70f48c58b9_670a875b29.jpg',
            ],
        ];
    }

    public function __construct(
        private PerfectCorpClient $perfectCorp,
        private MediaStorageService $media,
    ) {}

    /** @return array<string, mixed>|null */
    public function normalizeTryOnConfig(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $enabled = (bool) ($raw['enabled'] ?? false);
        $mode = is_string($raw['mode'] ?? null) ? $raw['mode'] : 'bag';
        if (! in_array($mode, self::MODES, true)) {
            $mode = 'bag';
        }

        $config = [
            'enabled' => $enabled,
            'mode' => $mode,
        ];

        if (! empty($raw['ref_image_url']) && is_string($raw['ref_image_url'])) {
            $config['ref_image_url'] = trim($raw['ref_image_url']);
        }

        if (in_array($mode, self::GENDER_STYLE_MODES, true)) {
            $gender = $raw['bag_gender_default'] ?? 'ask';
            $config['bag_gender_default'] = in_array($gender, ['female', 'male', 'ask'], true)
                ? $gender
                : 'ask';
            $styles = $this->stylesForMode($mode);
            $style = is_string($raw['bag_style'] ?? null) ? $raw['bag_style'] : 'random';
            $config['bag_style'] = in_array($style, $styles, true) ? $style : 'random';
        }

        if ($mode === 'clothes') {
            $category = is_string($raw['garment_category'] ?? null) ? $raw['garment_category'] : 'auto';
            $config['garment_category'] = in_array($category, self::GARMENT_CATEGORIES, true)
                ? $category
                : 'auto';
        }

        if ($mode === 'nail') {
            $effectType = is_string($raw['nail_effect_type'] ?? null) ? $raw['nail_effect_type'] : 'nail_polish';
            $config['nail_effect_type'] = in_array($effectType, self::NAIL_EFFECT_TYPES, true)
                ? $effectType
                : 'nail_polish';

            $subType = is_string($raw['nail_sub_type'] ?? null) ? $raw['nail_sub_type'] : 'color';
            $config['nail_sub_type'] = in_array($subType, self::NAIL_SUB_TYPES, true)
                ? $subType
                : 'color';

            $color = is_string($raw['nail_color'] ?? null) ? strtolower(trim($raw['nail_color'])) : '#c41e3a';
            $config['nail_color'] = preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : '#c41e3a';

            $textures = $config['nail_effect_type'] === 'press_on_nails'
                ? self::NAIL_PRESS_ON_TEXTURES
                : self::NAIL_POLISH_TEXTURES;
            $texture = is_string($raw['nail_texture'] ?? null) ? $raw['nail_texture'] : 'cream';
            $config['nail_texture'] = in_array($texture, $textures, true) ? $texture : 'cream';

            $shape = is_string($raw['nail_shape'] ?? null) ? $raw['nail_shape'] : 'square_oval';
            $config['nail_shape'] = in_array($shape, self::NAIL_SHAPES, true) ? $shape : 'square_oval';

            $length = is_numeric($raw['nail_length'] ?? null) ? (float) $raw['nail_length'] : 1.0;
            $config['nail_length'] = max(0.8, min(2.15, $length));
        }

        if ($mode === 'fabric') {
            $templateId = is_string($raw['fabric_template_id'] ?? null) ? trim($raw['fabric_template_id']) : '';
            if ($templateId !== '') {
                $config['fabric_template_id'] = $templateId;
            }
        }

        return $config;
    }

    /** @return list<string> */
    public function stylesForMode(string $mode): array
    {
        return match ($mode) {
            'hat' => self::HAT_STYLES,
            'shoes' => self::SHOES_STYLES,
            default => self::BAG_STYLES,
        };
    }

    public function resolveRefImageUrl(StoreProduct $product, ?array $tryOn = null): ?string
    {
        $tryOn ??= is_array($product->try_on) ? $product->try_on : null;
        $override = is_string($tryOn['ref_image_url'] ?? null) ? trim($tryOn['ref_image_url']) : '';
        if ($override !== '') {
            return $override;
        }

        if (filled($product->image_url)) {
            return (string) $product->image_url;
        }

        $images = is_array($product->images) ? $product->images : [];
        foreach ($images as $url) {
            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }
        }

        return null;
    }

    public function productAllowsTryOn(Store $store, StoreProduct $product): bool
    {
        if (! (bool) ($store->virtual_try_on_enabled ?? false)) {
            return false;
        }

        if ($product->status !== 'active') {
            return false;
        }

        $tryOn = is_array($product->try_on) ? $product->try_on : null;
        if (! ($tryOn['enabled'] ?? false)) {
            return false;
        }

        $mode = is_string($tryOn['mode'] ?? null) ? $tryOn['mode'] : 'bag';
        if (! in_array($mode, self::MODES, true)) {
            return false;
        }

        if ($mode === 'fabric') {
            return filled($tryOn['fabric_template_id'] ?? null);
        }

        if ($mode === 'nail' && ($tryOn['nail_sub_type'] ?? 'color') === 'color') {
            return true;
        }

        return $this->resolveRefImageUrl($product, $tryOn) !== null;
    }

    /**
     * @param  array{
     *   product_id: string,
     *   gender?: string|null,
     *   style?: string|null,
     *   garment_category?: string|null,
     *   src_image_url?: string|null,
     * }  $input
     */
    public function createSession(
        Store $store,
        array $input,
        ?UploadedFile $srcImage = null,
        ?Customer $customer = null,
    ): TryOnSession {
        if (! (bool) ($store->virtual_try_on_enabled ?? false)) {
            throw new RuntimeException('Virtual try-on is not enabled for this store.');
        }

        if (! $this->perfectCorp->isConfigured()) {
            throw new RuntimeException('Try-on provider is not configured.');
        }

        $product = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('id', $input['product_id'])
            ->where('status', 'active')
            ->first();

        if (! $product) {
            throw new RuntimeException('Product not found.');
        }

        if (! $this->productAllowsTryOn($store, $product)) {
            throw new RuntimeException('Try-on is not available for this product.');
        }

        $tryOn = is_array($product->try_on) ? $product->try_on : [];
        $mode = is_string($tryOn['mode'] ?? null) ? $tryOn['mode'] : 'bag';
        if (! in_array($mode, self::MODES, true)) {
            $mode = 'bag';
        }

        $needsRef = $this->modeNeedsRefImage($mode, $tryOn);
        $refUrl = $this->resolveRefImageUrl($product, $tryOn);
        if ($needsRef && $refUrl === null) {
            throw new RuntimeException('Product is missing a try-on reference image.');
        }

        if ($mode === 'fabric' && ! filled($tryOn['fabric_template_id'] ?? null)) {
            throw new RuntimeException('This fabric look is missing a template.');
        }

        $src = $this->storeShopperImage($store, $srcImage, $input['src_image_url'] ?? null);
        $srcUrl = $src['url'];

        $gender = null;
        $style = null;
        $garmentCategory = null;

        try {
            if ($this->perfectCorp->isStub()) {
                $srcRef = 'stub';
                $refRef = $needsRef ? 'stub' : null;
            } else {
                $srcRef = $this->perfectCorp->uploadImageBytes(
                    $src['bytes'],
                    $src['content_type'],
                    $src['file_name'],
                );
                $refRef = null;
                if ($needsRef && $refUrl) {
                    $refRef = $this->perfectCorp->uploadFromUrl($refUrl);
                }
            }

            $task = $this->startProviderTask($mode, $tryOn, $input, $srcRef, $refRef, $gender, $style, $garmentCategory);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Could not start try-on: '.$e->getMessage(),
                previous: $e,
            );
        }

        $session = TryOnSession::create([
            'store_id' => $store->id,
            'customer_id' => $customer?->id,
            'product_id' => $product->id,
            'mode' => $mode,
            'status' => 'processing',
            'provider' => 'perfectcorp',
            'provider_task_id' => $task['task_id'],
            'src_image_url' => $srcUrl,
            'ref_image_url' => $refUrl,
            'gender' => $gender,
            'style' => $style,
            'garment_category' => $garmentCategory,
            'meta' => [
                'stub' => $this->perfectCorp->isStub(),
                'product_name' => $product->name,
                'fabric_template_id' => $tryOn['fabric_template_id'] ?? null,
                'nail_effect_type' => $tryOn['nail_effect_type'] ?? null,
            ],
        ]);

        $delay = $this->perfectCorp->isStub()
            ? (int) config('perfectcorp.stub_delay_seconds', 4)
            : (int) config('perfectcorp.poll_interval_seconds', 3);

        try {
            PollTryOnSessionStatus::dispatch($session->id)->delay(now()->addSeconds(max(1, $delay)));
        } catch (\Throwable) {
            // Client polling via GET still drives completion when the queue is offline.
        }

        return $session;
    }

    public function refreshSession(TryOnSession $session): TryOnSession
    {
        if ($session->isTerminal()) {
            return $session;
        }

        $taskId = (string) $session->provider_task_id;
        if ($taskId === '') {
            $session->update([
                'status' => 'error',
                'error_code' => 'missing_task',
                'error_message' => 'Try-on task was not started.',
            ]);

            return $session->fresh() ?? $session;
        }

        $session->poll_attempts = (int) $session->poll_attempts + 1;
        $session->save();

        // Stub: hold "processing" until stub_delay elapses so the PDP sheet can animate.
        if ($this->perfectCorp->isStub() || str_starts_with($taskId, 'stub_')) {
            $delay = (int) config('perfectcorp.stub_delay_seconds', 4);
            $readyAt = optional($session->created_at)?->copy()->addSeconds(max(1, $delay));
            if ($readyAt && now()->lt($readyAt)) {
                return $session;
            }

            $session->update([
                'status' => 'success',
                'result_url' => data_get($session->meta, 'purpose') === 'catalog_model'
                    ? ($session->src_image_url ?: $session->ref_image_url)
                    : ($session->ref_image_url ?: $session->src_image_url),
                'error_code' => null,
                'error_message' => null,
            ]);

            return $session->fresh() ?? $session;
        }

        try {
            $status = $this->perfectCorp->getTaskStatus((string) $session->mode, $taskId);
        } catch (\Throwable $e) {
            if ($session->poll_attempts >= (int) config('perfectcorp.max_poll_attempts', 40)) {
                $session->update([
                    'status' => 'error',
                    'error_code' => 'provider_error',
                    'error_message' => 'Could not create this look — try a different photo.',
                ]);
            }

            return $session->fresh() ?? $session;
        }

        $taskStatus = $status['task_status'];

        if ($taskStatus === 'success') {
            $remoteUrl = is_string($status['result_url'] ?? null) ? $status['result_url'] : null;
            $resultUrl = $remoteUrl
                ? $this->persistResultImage($session, $remoteUrl)
                : null;

            if (! $resultUrl) {
                $session->update([
                    'status' => 'error',
                    'error_code' => 'missing_result',
                    'error_message' => 'Couldn\'t create this look — try a different photo.',
                ]);

                return $session->fresh() ?? $session;
            }

            $session->update([
                'status' => 'success',
                'result_url' => $resultUrl,
                'error_code' => null,
                'error_message' => null,
            ]);

            return $session->fresh() ?? $session;
        }

        if ($taskStatus === 'error') {
            $rawError = $status['error'] ?? null;
            $errorCode = is_string($rawError)
                ? $rawError
                : (is_array($rawError) ? (string) ($rawError['error_code'] ?? $rawError['code'] ?? 'error_inference') : 'error_inference');

            $session->update([
                'status' => 'error',
                'error_code' => $errorCode,
                'error_message' => $this->shopperErrorMessage($errorCode),
            ]);

            return $session->fresh() ?? $session;
        }

        if ($session->poll_attempts >= (int) config('perfectcorp.max_poll_attempts', 40)) {
            $session->update([
                'status' => 'error',
                'error_code' => 'timeout',
                'error_message' => 'Still working — refresh or try again in a moment.',
            ]);
        }

        return $session->fresh() ?? $session;
    }

    /** @return array<string, mixed> */
    public function formatSession(TryOnSession $session): array
    {
        return [
            'id' => $session->id,
            'product_id' => $session->product_id,
            'mode' => $session->mode,
            'status' => $session->status,
            'result_url' => $this->media->browserUrl($session->result_url),
            'error_code' => $session->error_code,
            'error_message' => $session->error_message,
            'gender' => $session->gender,
            'style' => $session->style,
            'garment_category' => $session->garment_category,
            'stub' => (bool) data_get($session->meta, 'stub', false),
            'purpose' => data_get($session->meta, 'purpose'),
            'created_at' => optional($session->created_at)?->toIso8601String(),
            'updated_at' => optional($session->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * Dress a model in a garment photo for merchant catalog imagery.
     */
    public function createCatalogLook(
        Store $store,
        string $garmentImageUrl,
        string $modelImageUrl,
        string $garmentCategory = 'auto',
        ?string $productId = null,
        ?string $modelId = null,
    ): TryOnSession {
        if (! $this->perfectCorp->isConfigured()) {
            throw new RuntimeException('Try-on provider is not configured.');
        }

        $garmentImageUrl = trim($garmentImageUrl);
        $modelImageUrl = trim($modelImageUrl);
        if ($garmentImageUrl === '' || $modelImageUrl === '') {
            throw new RuntimeException('A product photo and a model photo are required.');
        }

        if (! in_array($garmentCategory, self::GARMENT_CATEGORIES, true)) {
            $garmentCategory = 'auto';
        }

        try {
            if ($this->perfectCorp->isStub()) {
                $srcRef = 'stub';
                $refRef = 'stub';
            } else {
                $srcRef = $this->perfectCorp->uploadFromUrl($modelImageUrl);
                $refRef = $this->perfectCorp->uploadFromUrl($garmentImageUrl);
            }

            $task = $this->perfectCorp->createClothTask($srcRef, $refRef, $garmentCategory);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Could not start model look: '.$e->getMessage(),
                previous: $e,
            );
        }

        $session = TryOnSession::create([
            'store_id' => $store->id,
            'customer_id' => null,
            'product_id' => $productId,
            'mode' => 'clothes',
            'status' => 'processing',
            'provider' => 'perfectcorp',
            'provider_task_id' => $task['task_id'],
            'src_image_url' => $modelImageUrl,
            'ref_image_url' => $garmentImageUrl,
            'garment_category' => $garmentCategory,
            'meta' => [
                'stub' => $this->perfectCorp->isStub(),
                'purpose' => 'catalog_model',
                'model_id' => $modelId,
            ],
        ]);

        $delay = $this->perfectCorp->isStub()
            ? (int) config('perfectcorp.stub_delay_seconds', 4)
            : (int) config('perfectcorp.poll_interval_seconds', 3);

        try {
            PollTryOnSessionStatus::dispatch($session->id)->delay(now()->addSeconds(max(1, $delay)));
        } catch (\Throwable) {
            // Client polling via GET still drives completion when the queue is offline.
        }

        return $session;
    }

    private function persistResultImage(TryOnSession $session, string $remoteUrl): ?string
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(60)->get($remoteUrl);
            if (! $response->successful()) {
                return $remoteUrl;
            }

            $bytes = $response->body();
            if ($bytes === '') {
                return $remoteUrl;
            }

            $mime = strtolower((string) ($response->header('Content-Type') ?: 'image/jpeg'));
            $ext = match (true) {
                str_contains($mime, 'png') => 'png',
                str_contains($mime, 'webp') => 'webp',
                default => 'jpg',
            };

            $relative = 'storehause/try-on/'.$session->store_id.'/results/'.$session->id.'.'.$ext;

            return $this->media->store($relative, $bytes, $mime);
        } catch (\Throwable) {
            return $remoteUrl;
        }
    }

    private function shopperErrorMessage(string $errorCode): string
    {
        return match ($errorCode) {
            'error_no_face' => 'We need a clearer photo with your face fully visible.',
            'error_pose' => 'We need a clearer standing photo facing the camera.',
            'error_invalid_src', 'error_invalid_ref', 'error_apply_region_mismatch' => 'This photo or product image isn’t try-on ready — try another photo.',
            'error_nsfw_content_detected' => 'Couldn’t create this look — try a different photo.',
            'error_download_image' => 'Couldn’t load one of the photos from storage — try again in a moment.',
            'PHOTO_DETECTION_FAIL' => 'We couldn’t find the right body area in this photo — try another angle.',
            'OBJECT_DETECTION_FAIL' => 'We couldn’t read the product image — try a clearer product photo.',
            'PHOTO_CHECK_INVALID' => 'This pose or crop isn’t try-on ready — try a clearer photo.',
            'error_apply_region_not_detected' => 'We couldn’t find clothing in this photo — try a standing full-body shot.',
            default => 'Couldn\'t create this look — try a different photo.',
        };
    }

    /**
     * @param  array<string, mixed>  $tryOn
     */
    private function modeNeedsRefImage(string $mode, array $tryOn): bool
    {
        if ($mode === 'fabric') {
            return false;
        }

        if ($mode === 'nail' && ($tryOn['nail_sub_type'] ?? 'color') === 'color') {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $tryOn
     * @param  array<string, mixed>  $input
     * @return array{task_id: string}
     */
    private function startProviderTask(
        string $mode,
        array $tryOn,
        array $input,
        string $srcRef,
        ?string $refRef,
        ?string &$gender,
        ?string &$style,
        ?string &$garmentCategory,
    ): array {
        if ($mode === 'clothes') {
            $garmentCategory = is_string($input['garment_category'] ?? null)
                ? $input['garment_category']
                : ($tryOn['garment_category'] ?? 'auto');
            if (! in_array($garmentCategory, self::GARMENT_CATEGORIES, true)) {
                $garmentCategory = 'auto';
            }

            return $this->perfectCorp->createClothTask($srcRef, (string) $refRef, $garmentCategory);
        }

        if (in_array($mode, self::GENDER_STYLE_MODES, true)) {
            $genderDefault = $tryOn['bag_gender_default'] ?? 'ask';
            $gender = $input['gender'] ?? null;
            if (! in_array($gender, ['female', 'male'], true)) {
                $gender = in_array($genderDefault, ['female', 'male'], true) ? $genderDefault : 'female';
            }

            $allowedStyles = $this->stylesForMode($mode);
            $styleDefault = is_string($tryOn['bag_style'] ?? null) ? $tryOn['bag_style'] : 'random';
            $style = $input['style'] ?? $styleDefault;
            if (! in_array($style, $allowedStyles, true)) {
                $style = 'random';
            }

            return match ($mode) {
                'hat' => $this->perfectCorp->createHatTask($srcRef, (string) $refRef, $gender, $style),
                'shoes' => $this->perfectCorp->createShoesTask($srcRef, (string) $refRef, $gender, $style),
                default => $this->perfectCorp->createBagTask($srcRef, (string) $refRef, $gender, $style),
            };
        }

        if ($mode === 'nail') {
            return $this->perfectCorp->createNailTask($this->nailPayload($tryOn, $srcRef, $refRef));
        }

        if ($mode === 'watch') {
            return $this->perfectCorp->createWatchTask($srcRef, (string) $refRef);
        }

        if ($mode === 'necklace') {
            return $this->perfectCorp->createNecklaceTask($srcRef, (string) $refRef);
        }

        return $this->perfectCorp->createFabricTask($srcRef, (string) ($tryOn['fabric_template_id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $tryOn
     * @return array<string, mixed>
     */
    private function nailPayload(array $tryOn, string $srcRef, ?string $refRef): array
    {
        $effectType = in_array($tryOn['nail_effect_type'] ?? null, self::NAIL_EFFECT_TYPES, true)
            ? $tryOn['nail_effect_type']
            : 'nail_polish';
        $subType = in_array($tryOn['nail_sub_type'] ?? null, self::NAIL_SUB_TYPES, true)
            ? $tryOn['nail_sub_type']
            : 'color';
        $texture = is_string($tryOn['nail_texture'] ?? null) ? $tryOn['nail_texture'] : 'cream';
        $color = is_string($tryOn['nail_color'] ?? null) ? $tryOn['nail_color'] : '#c41e3a';
        $shape = is_string($tryOn['nail_shape'] ?? null) ? $tryOn['nail_shape'] : 'square_oval';
        $length = is_numeric($tryOn['nail_length'] ?? null) ? (float) $tryOn['nail_length'] : 1.0;

        $payload = [
            'version' => '1.0',
            'src_file_id' => $srcRef,
            'effect_type' => $effectType,
            'effects' => [],
        ];

        if ($subType === 'design' && filled($refRef)) {
            $payload['ref_file_ids'] = [$refRef];
        }

        foreach (self::NAIL_FINGERS as $finger) {
            $effect = [
                'sub_type' => $subType,
                'finger' => $finger,
                'texture' => $texture,
                'reflection' => $subType === 'design' ? 100 : 50,
                'contrast' => 50,
                'roughness' => 0,
            ];

            if ($subType === 'color') {
                $effect['color'] = $color;
                if ($effectType === 'nail_polish') {
                    $effect['transparency'] = 0;
                }
                if ($effectType === 'press_on_nails') {
                    $effect['shape'] = $shape;
                    $effect['length'] = $length;
                }
            } else {
                $effect['ref_file_index'] = 0;
            }

            $payload['effects'][] = $effect;
        }

        return $payload;
    }

    /**
     * @return array{url: string, bytes: string, content_type: string, file_name: string}
     */
    private function storeShopperImage(Store $store, ?UploadedFile $file, ?string $srcImageUrl): array
    {
        if ($file instanceof UploadedFile) {
            $ext = strtolower((string) $file->getClientOriginalExtension()) ?: 'jpg';
            $name = Str::uuid()->toString().'.'.$ext;
            $bytes = $file->getContent();
            if ($bytes === '') {
                throw new RuntimeException('Could not read the uploaded photo.');
            }
            $mime = $file->getMimeType() ?: 'image/jpeg';
            $url = $this->media->storeUpload('storehause/try-on/'.$store->id, $file, $name);

            return [
                'url' => $url,
                'bytes' => $bytes,
                'content_type' => $mime,
                'file_name' => $name,
            ];
        }

        $url = is_string($srcImageUrl) ? trim($srcImageUrl) : '';
        if ($url !== '' && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))) {
            $image = $this->perfectCorp->resolveImageForUpload($url);

            return [
                'url' => $url,
                'bytes' => $image['bytes'],
                'content_type' => $image['content_type'],
                'file_name' => $image['file_name'],
            ];
        }

        // data:image/...;base64,...
        if ($url !== '' && preg_match('#^data:image/(jpeg|jpg|png|webp|heic);base64,#i', $url)) {
            return $this->storeDataUrl($store, $url);
        }

        throw new RuntimeException('A shopper photo is required.');
    }

    /**
     * @return array{url: string, bytes: string, content_type: string, file_name: string}
     */
    private function storeDataUrl(Store $store, string $dataUrl): array
    {
        if (! preg_match('#^data:image/(jpeg|jpg|png|webp|heic);base64,(.+)$#i', $dataUrl, $matches)) {
            throw new RuntimeException('Invalid photo data.');
        }

        $ext = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
        $binary = base64_decode($matches[2], true);
        if ($binary === false || strlen($binary) < 100) {
            throw new RuntimeException('Invalid photo data.');
        }

        if (strlen($binary) > 10 * 1024 * 1024) {
            throw new RuntimeException('Photo must be under 10MB.');
        }

        $name = Str::uuid()->toString().'.'.$ext;
        $mime = match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            default => 'image/jpeg',
        };

        $storedUrl = $this->media->store('storehause/try-on/'.$store->id.'/'.$name, $binary, $mime);

        return [
            'url' => $storedUrl,
            'bytes' => $binary,
            'content_type' => $mime,
            'file_name' => $name,
        ];
    }
}
