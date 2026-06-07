<?php

namespace App\Http\Controllers;

use App\Models\AppTransaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AppTransactionsController extends Controller
{
  private const TRANSACTION_TYPE_PRIORITY = [
    'other' => 0,
    'transfer' => 10,
    'airtime' => 20,
    'electricity' => 20,
    'deposit' => 20,
    'withdraw' => 20,
    'private_transfer' => 20,
    'offramp' => 30,
    'commerce' => 30,
    'swap' => 40,
  ];

  private const STATUS_PRIORITY = [
    'pending' => 0,
    'submitted' => 10,
    'failed' => 20,
    'confirmed' => 30,
  ];

  /**
   * Ingest or update an app-originated transaction (wallet, no auth).
   * Idempotent on transaction_hash + cluster when signature is present.
   */
  public function store(Request $request): JsonResponse
  {
    if (! Schema::hasTable('app_transactions')) {
      return response()->json([
        'success' => false,
        'message' => 'Transaction storage not available',
      ], 503);
    }

    $validator = Validator::make($request->all(), [
      'client_reference' => 'nullable|string|max:64',
      'signature' => 'nullable|string|max:128',
      'transaction_hash' => 'nullable|string|max:128',
      'cluster' => 'required|in:mainnet,devnet',
      'wallet_address' => 'required|string|max:64',
      'username' => 'nullable|string|max:64',
      'mobile_number' => 'nullable|string|max:32',
      'transaction_type' => 'required|string|max:32',
      'status' => 'nullable|in:pending,submitted,confirmed,failed',
      'provider' => 'nullable|string|max:64',
      'amount' => 'nullable|numeric',
      'token' => 'nullable|string|max:32',
      'input_token_mint' => 'nullable|string|max:64',
      'input_token_symbol' => 'nullable|string|max:32',
      'input_amount' => 'nullable|numeric',
      'input_amount_usd' => 'nullable|numeric',
      'output_token_mint' => 'nullable|string|max:64',
      'output_token_symbol' => 'nullable|string|max:32',
      'output_amount' => 'nullable|numeric',
      'output_amount_usd' => 'nullable|numeric',
      'platform_fee_amount' => 'nullable|numeric',
      'platform_fee_token' => 'nullable|string|max:32',
      'platform_fee_usd' => 'nullable|numeric',
      'network_fee_lamports' => 'nullable|integer|min:0',
      'recipient_address' => 'nullable|string|max:64',
      'app_called' => 'nullable|string|max:64',
      'raw_metadata' => 'nullable|array',
    ]);

    if ($validator->fails()) {
      return response()->json([
        'success' => false,
        'message' => 'Validation failed',
        'errors' => $validator->errors(),
      ], 422);
    }

    $data = $validator->validated();
    $signature = $data['signature'] ?? $data['transaction_hash'] ?? null;
    $cluster = $data['cluster'];
    $status = $data['status'] ?? 'submitted';

    $payload = [
      'client_reference' => $data['client_reference'] ?? null,
      'transaction_id' => $data['client_reference'] ?? ($signature ? Str::limit($signature, 64, '') : Str::uuid()->toString()),
      'transaction_hash' => $signature,
      'cluster' => $cluster,
      'wallet_address' => $data['wallet_address'],
      'username' => $data['username'] ?? null,
      'mobile_number' => $data['mobile_number'] ?? null,
      'transaction_type' => $data['transaction_type'],
      'status' => $status,
      'provider' => $data['provider'] ?? null,
      'amount' => isset($data['amount']) ? (string) $data['amount'] : (isset($data['input_amount']) ? (string) $data['input_amount'] : null),
      'token' => $data['token'] ?? $data['input_token_symbol'] ?? null,
      'input_token_mint' => $data['input_token_mint'] ?? null,
      'input_token_symbol' => $data['input_token_symbol'] ?? null,
      'input_amount' => $data['input_amount'] ?? null,
      'input_amount_usd' => $data['input_amount_usd'] ?? null,
      'output_token_mint' => $data['output_token_mint'] ?? null,
      'output_token_symbol' => $data['output_token_symbol'] ?? null,
      'output_amount' => $data['output_amount'] ?? null,
      'output_amount_usd' => $data['output_amount_usd'] ?? null,
      'platform_fee_amount' => $data['platform_fee_amount'] ?? null,
      'platform_fee_token' => $data['platform_fee_token'] ?? null,
      'platform_fee_usd' => $data['platform_fee_usd'] ?? null,
      'network_fee_lamports' => $data['network_fee_lamports'] ?? null,
      'recipient_address' => $data['recipient_address'] ?? null,
      'app_called' => $data['app_called'] ?? null,
      'raw_metadata' => $data['raw_metadata'] ?? null,
    ];

    if ($status === 'confirmed') {
      $payload['confirmed_at'] = now();
    }

    $existing = null;
    if ($signature) {
      $existing = AppTransaction::where('transaction_hash', $signature)
        ->where('cluster', $cluster)
        ->first();
    } elseif (! empty($data['client_reference'])) {
      $existing = AppTransaction::where('client_reference', $data['client_reference'])->first();
    }

    if ($existing) {
      $existing->fill($this->mergeExistingPayload($existing, $payload));
      if ($status === 'confirmed' && ! $existing->confirmed_at) {
        $existing->confirmed_at = now();
      }
      $existing->save();

      return response()->json([
        'success' => true,
        'data' => $this->formatTransaction($existing),
      ]);
    }

    $record = AppTransaction::create($payload);

    return response()->json([
      'success' => true,
      'data' => $this->formatTransaction($record),
    ], 201);
  }

  private function mergeExistingPayload(AppTransaction $existing, array $payload): array
  {
    $merged = array_filter($payload, fn ($v) => $v !== null);

    $existingType = (string) $existing->transaction_type;
    $incomingType = (string) ($payload['transaction_type'] ?? $existingType);
    if (
      (self::TRANSACTION_TYPE_PRIORITY[$incomingType] ?? 0) <
      (self::TRANSACTION_TYPE_PRIORITY[$existingType] ?? 0)
    ) {
      unset($merged['transaction_type']);
    }

    $existingStatus = (string) $existing->status;
    $incomingStatus = (string) ($payload['status'] ?? $existingStatus);
    if (
      (self::STATUS_PRIORITY[$incomingStatus] ?? 0) <
      (self::STATUS_PRIORITY[$existingStatus] ?? 0)
    ) {
      unset($merged['status']);
    }

    if (! empty($existing->app_called) && ($payload['app_called'] ?? null) === 'HeySolana') {
      unset($merged['app_called']);
    }

    $metadata = array_merge(
      is_array($existing->raw_metadata) ? $existing->raw_metadata : [],
      is_array($payload['raw_metadata'] ?? null) ? $payload['raw_metadata'] : []
    );
    if ($metadata !== []) {
      $merged['raw_metadata'] = $metadata;
    }

    return $merged;
  }

  /**
   * TVP / fee metrics for admin dashboard.
   */
  public function metrics(Request $request): JsonResponse
  {
    if (! Schema::hasTable('app_transactions')) {
      return response()->json([
        'available' => false,
        'message' => 'Run migrations to enable app_transactions.',
      ]);
    }

    $cluster = $request->query('cluster', 'mainnet');
    if (! in_array($cluster, ['mainnet', 'devnet', 'all'], true)) {
      $cluster = 'mainnet';
    }

    $days = min(365, max(1, (int) $request->query('days', 30)));
    $since = Carbon::now()->subDays($days)->startOfDay();

    $base = DB::table('app_transactions')
      ->where('status', 'confirmed')
      ->where('created_at', '>=', $since);

    if ($cluster !== 'all') {
      $base->where('cluster', $cluster);
    }

    $summary = (clone $base)
      ->selectRaw('COUNT(*) as tx_count')
      ->selectRaw('COALESCE(SUM(input_amount_usd), 0) as total_input_usd')
      ->selectRaw('COALESCE(SUM(output_amount_usd), 0) as total_output_usd')
      ->selectRaw('COALESCE(SUM(platform_fee_usd), 0) as total_fee_usd')
      ->first();

    $byType = (clone $base)
      ->select('transaction_type')
      ->selectRaw('COUNT(*) as count')
      ->selectRaw('COALESCE(SUM(input_amount_usd), 0) as volume_usd')
      ->selectRaw('COALESCE(SUM(platform_fee_usd), 0) as fee_usd')
      ->groupBy('transaction_type')
      ->orderByDesc('volume_usd')
      ->get();

    $byDay = (clone $base)
      ->selectRaw('DATE(created_at) as date')
      ->selectRaw('COUNT(*) as count')
      ->selectRaw('COALESCE(SUM(input_amount_usd), 0) as volume_usd')
      ->selectRaw('COALESCE(SUM(platform_fee_usd), 0) as fee_usd')
      ->groupByRaw('DATE(created_at)')
      ->orderBy('date')
      ->get();

    $byCluster = DB::table('app_transactions')
      ->where('status', 'confirmed')
      ->where('created_at', '>=', $since)
      ->select('cluster')
      ->selectRaw('COUNT(*) as count')
      ->selectRaw('COALESCE(SUM(input_amount_usd), 0) as volume_usd')
      ->groupBy('cluster')
      ->get();

    $byApp = $this->aggregateVolumeByLabel($base, 'app_called', 'app_called');
    $byProvider = $this->aggregateVolumeByLabel($base, 'provider', 'provider');

    return response()->json([
      'available' => true,
      'cluster' => $cluster,
      'days' => $days,
      'summary' => [
        'tx_count' => (int) ($summary->tx_count ?? 0),
        'total_input_usd' => round((float) ($summary->total_input_usd ?? 0), 2),
        'total_output_usd' => round((float) ($summary->total_output_usd ?? 0), 2),
        'total_fee_usd' => round((float) ($summary->total_fee_usd ?? 0), 2),
      ],
      'by_type' => $byType,
      'by_day' => $byDay,
      'by_cluster' => $byCluster,
      'by_app' => $byApp,
      'by_provider' => $byProvider,
    ]);
  }

  /**
   * @param  \Illuminate\Database\Query\Builder  $base
   * @return array<int, array<string, mixed>>
   */
  private function aggregateVolumeByLabel($base, string $column, string $labelKey): array
  {
    if (! in_array($column, ['app_called', 'provider'], true)) {
      return [];
    }

    $rows = (clone $base)
      ->selectRaw("{$column} as label")
      ->selectRaw('COUNT(*) as count')
      ->selectRaw('COALESCE(SUM(input_amount_usd), 0) as volume_usd')
      ->selectRaw('COALESCE(SUM(platform_fee_usd), 0) as fee_usd')
      ->groupBy($column)
      ->get();

    $totals = [];
    foreach ($rows as $row) {
      $label = trim((string) ($row->label ?? ''));
      $label = $label !== '' ? $label : 'Unknown';

      if (! isset($totals[$label])) {
        $totals[$label] = [
          $labelKey => $label,
          'count' => 0,
          'volume_usd' => 0.0,
          'fee_usd' => 0.0,
        ];
      }

      $totals[$label]['count'] += (int) $row->count;
      $totals[$label]['volume_usd'] += (float) $row->volume_usd;
      $totals[$label]['fee_usd'] += (float) $row->fee_usd;
    }

    $result = array_values($totals);
    usort($result, fn ($a, $b) => $b['volume_usd'] <=> $a['volume_usd']);

    return array_map(fn ($row) => [
      $labelKey => $row[$labelKey],
      'count' => $row['count'],
      'volume_usd' => round($row['volume_usd'], 2),
      'fee_usd' => round($row['fee_usd'], 2),
    ], $result);
  }

  private function formatTransaction(AppTransaction $tx): array
  {
    return [
      'id' => $tx->id,
      'client_reference' => $tx->client_reference,
      'signature' => $tx->transaction_hash,
      'cluster' => $tx->cluster,
      'wallet_address' => $tx->wallet_address,
      'transaction_type' => $tx->transaction_type,
      'status' => $tx->status,
      'input_amount' => $tx->input_amount,
      'input_amount_usd' => $tx->input_amount_usd,
      'output_amount' => $tx->output_amount,
      'platform_fee_usd' => $tx->platform_fee_usd,
      'confirmed_at' => $tx->confirmed_at?->toIso8601String(),
      'created_at' => $tx->created_at?->toIso8601String(),
    ];
  }
}
