<?php

namespace App\Services;

use App\Jobs\PollTryOnSessionStatus;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\TryOnSession;
use App\Services\PerfectCorp\PerfectCorpClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class TryOnService
{
    public const BAG_STYLES = [
        'random',
        'style_parisian_chic',
        'style_urban_chic',
        'style_mediterranean_chic',
        'style_art_deco_style',
    ];

    public const GARMENT_CATEGORIES = [
        'auto',
        'full_body',
        'upper_body',
        'lower_body',
        'outerwear',
        'shoes',
    ];

    public function __construct(
        private PerfectCorpClient $perfectCorp,
    ) {}

    /** @return array<string, mixed>|null */
    public function normalizeTryOnConfig(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $enabled = (bool) ($raw['enabled'] ?? false);
        $mode = ($raw['mode'] ?? 'bag') === 'clothes' ? 'clothes' : 'bag';

        $config = [
            'enabled' => $enabled,
            'mode' => $mode,
        ];

        if (! empty($raw['ref_image_url']) && is_string($raw['ref_image_url'])) {
            $config['ref_image_url'] = trim($raw['ref_image_url']);
        }

        if ($mode === 'bag') {
            $gender = $raw['bag_gender_default'] ?? 'ask';
            $config['bag_gender_default'] = in_array($gender, ['female', 'male', 'ask'], true)
                ? $gender
                : 'ask';
            $style = is_string($raw['bag_style'] ?? null) ? $raw['bag_style'] : 'random';
            $config['bag_style'] = in_array($style, self::BAG_STYLES, true) ? $style : 'random';
        }

        if ($mode === 'clothes') {
            $category = is_string($raw['garment_category'] ?? null) ? $raw['garment_category'] : 'auto';
            $config['garment_category'] = in_array($category, self::GARMENT_CATEGORIES, true)
                ? $category
                : 'auto';
        }

        return $config;
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
        $mode = ($tryOn['mode'] ?? 'bag') === 'clothes' ? 'clothes' : 'bag';

        $refUrl = $this->resolveRefImageUrl($product, $tryOn);
        if ($refUrl === null) {
            throw new RuntimeException('Product is missing a try-on reference image.');
        }

        $srcUrl = $this->storeShopperImage($store, $srcImage, $input['src_image_url'] ?? null);

        $gender = null;
        $style = null;
        $garmentCategory = null;

        try {
            if ($this->perfectCorp->isStub()) {
                $srcRef = 'stub';
                $refRef = 'stub';
            } else {
                $srcRef = $this->perfectCorp->uploadFromUrl($srcUrl);
                $refRef = $this->perfectCorp->uploadFromUrl($refUrl);
            }

            if ($mode === 'clothes') {
                $garmentCategory = is_string($input['garment_category'] ?? null)
                    ? $input['garment_category']
                    : ($tryOn['garment_category'] ?? 'auto');
                if (! in_array($garmentCategory, self::GARMENT_CATEGORIES, true)) {
                    $garmentCategory = 'auto';
                }

                $task = $this->perfectCorp->createClothTask($srcRef, $refRef, $garmentCategory);
            } else {
                $genderDefault = $tryOn['bag_gender_default'] ?? 'ask';
                $gender = $input['gender'] ?? null;
                if (! in_array($gender, ['female', 'male'], true)) {
                    $gender = in_array($genderDefault, ['female', 'male'], true) ? $genderDefault : 'female';
                }

                $styleDefault = is_string($tryOn['bag_style'] ?? null) ? $tryOn['bag_style'] : 'random';
                $style = $input['style'] ?? $styleDefault;
                if (! in_array($style, self::BAG_STYLES, true)) {
                    $style = 'random';
                }

                $task = $this->perfectCorp->createBagTask($srcRef, $refRef, $gender, $style);
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Could not start try-on: '.$e->getMessage(),
                previous: $e,
            );
        }

        $session = TryOnSession::create([
            'store_id' => $store->id,
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
                'result_url' => $session->ref_image_url ?: $session->src_image_url,
                'error_code' => null,
                'error_message' => null,
            ]);

            return $session->fresh() ?? $session;
        }

        try {
            $status = $session->mode === 'clothes'
                ? $this->perfectCorp->getClothTask($taskId)
                : $this->perfectCorp->getBagTask($taskId);
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
            'result_url' => $session->result_url,
            'error_code' => $session->error_code,
            'error_message' => $session->error_message,
            'gender' => $session->gender,
            'style' => $session->style,
            'garment_category' => $session->garment_category,
            'stub' => (bool) data_get($session->meta, 'stub', false),
            'created_at' => optional($session->created_at)?->toIso8601String(),
            'updated_at' => optional($session->updated_at)?->toIso8601String(),
        ];
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

            $dir = public_path('storehause/try-on/'.$session->store_id.'/results');
            File::ensureDirectoryExists($dir);
            $name = $session->id.'.'.$ext;
            File::put($dir.'/'.$name, $bytes);

            return url('storehause/try-on/'.$session->store_id.'/results/'.$name);
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
            'error_download_image' => 'Couldn’t load one of the images — try again.',
            default => 'Couldn\'t create this look — try a different photo.',
        };
    }

    private function storeShopperImage(Store $store, ?UploadedFile $file, ?string $srcImageUrl): string
    {
        if ($file instanceof UploadedFile) {
            $dir = public_path('storehause/try-on/'.$store->id);
            File::ensureDirectoryExists($dir);
            $name = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
            $file->move($dir, $name);

            return url('storehause/try-on/'.$store->id.'/'.$name);
        }

        $url = is_string($srcImageUrl) ? trim($srcImageUrl) : '';
        if ($url !== '' && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))) {
            return $url;
        }

        // data:image/...;base64,...
        if ($url !== '' && preg_match('#^data:image/(jpeg|jpg|png|webp|heic);base64,#i', $url)) {
            return $this->storeDataUrl($store, $url);
        }

        throw new RuntimeException('A shopper photo is required.');
    }

    private function storeDataUrl(Store $store, string $dataUrl): string
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

        $dir = public_path('storehause/try-on/'.$store->id);
        File::ensureDirectoryExists($dir);
        $name = Str::uuid()->toString().'.'.$ext;
        File::put($dir.'/'.$name, $binary);

        return url('storehause/try-on/'.$store->id.'/'.$name);
    }
}
