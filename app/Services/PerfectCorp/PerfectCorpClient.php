<?php

namespace App\Services\PerfectCorp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PerfectCorpClient
{
    public function isStub(): bool
    {
        return (bool) config('perfectcorp.stub', true);
    }

    public function isConfigured(): bool
    {
        if ($this->isStub()) {
            return true;
        }

        return filled(config('perfectcorp.api_key'));
    }

    /**
     * @return array{task_id: string}
     */
    public function createBagTask(string $srcFileUrl, string $refFileUrl, string $gender, string $style): array
    {
        if ($this->isStub()) {
            return ['task_id' => 'stub_bag_'.Str::uuid()->toString()];
        }

        $response = $this->http()->post('/s2s/v2.0/task/bag', [
            'src_file_url' => $srcFileUrl,
            'ref_file_url' => $refFileUrl,
            'gender' => $gender,
            'style' => $style,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('PerfectCorp bag task failed: '.$response->body());
        }

        $taskId = data_get($response->json(), 'data.task_id');
        if (! is_string($taskId) || $taskId === '') {
            throw new RuntimeException('PerfectCorp bag task returned no task_id.');
        }

        return ['task_id' => $taskId];
    }

    /**
     * @return array{task_id: string}
     */
    public function createClothTask(string $srcFileUrl, string $refFileUrl, string $garmentCategory): array
    {
        if ($this->isStub()) {
            return ['task_id' => 'stub_cloth_'.Str::uuid()->toString()];
        }

        $response = $this->http()->post('/s2s/v2.0/task/cloth-v4', [
            'src_file_url' => $srcFileUrl,
            'ref_file_url' => $refFileUrl,
            'garment_category' => $garmentCategory,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('PerfectCorp cloth task failed: '.$response->body());
        }

        $taskId = data_get($response->json(), 'data.task_id');
        if (! is_string($taskId) || $taskId === '') {
            throw new RuntimeException('PerfectCorp cloth task returned no task_id.');
        }

        return ['task_id' => $taskId];
    }

    /**
     * @return array{task_status: string, result_url: ?string, error: mixed}
     */
    public function getBagTask(string $taskId): array
    {
        if ($this->isStub() || str_starts_with($taskId, 'stub_')) {
            return [
                'task_status' => 'success',
                'result_url' => null,
                'error' => null,
            ];
        }

        $response = $this->http()->get('/s2s/v2.0/task/bag/'.$taskId);

        if (! $response->successful()) {
            throw new RuntimeException('PerfectCorp bag status failed: '.$response->body());
        }

        $json = $response->json();

        return [
            'task_status' => (string) data_get($json, 'data.task_status', 'unknown'),
            'result_url' => data_get($json, 'data.results.url'),
            'error' => data_get($json, 'data.error'),
        ];
    }

    /**
     * @return array{task_status: string, result_url: ?string, error: mixed}
     */
    public function getClothTask(string $taskId): array
    {
        if ($this->isStub() || str_starts_with($taskId, 'stub_')) {
            return [
                'task_status' => 'success',
                'result_url' => null,
                'error' => null,
            ];
        }

        $response = $this->http()->get('/s2s/v2.0/task/cloth-v4/'.$taskId);

        if (! $response->successful()) {
            throw new RuntimeException('PerfectCorp cloth status failed: '.$response->body());
        }

        $json = $response->json();

        return [
            'task_status' => (string) data_get($json, 'data.task_status', 'unknown'),
            'result_url' => data_get($json, 'data.results.url'),
            'error' => data_get($json, 'data.error'),
        ];
    }

    private function http(): PendingRequest
    {
        $apiKey = (string) config('perfectcorp.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('PerfectCorp API key is not configured.');
        }

        return Http::baseUrl((string) config('perfectcorp.base_url'))
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }
}
