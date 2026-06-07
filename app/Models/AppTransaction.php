<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppTransaction extends Model
{
    protected $table = 'app_transactions';

    protected $fillable = [
        'client_reference',
        'transaction_id',
        'transaction_hash',
        'cluster',
        'wallet_address',
        'username',
        'mobile_number',
        'transaction_type',
        'status',
        'provider',
        'amount',
        'token',
        'input_token_mint',
        'input_token_symbol',
        'input_amount',
        'input_amount_usd',
        'output_token_mint',
        'output_token_symbol',
        'output_amount',
        'output_amount_usd',
        'platform_fee_amount',
        'platform_fee_token',
        'platform_fee_usd',
        'network_fee_lamports',
        'recipient_address',
        'app_called',
        'raw_metadata',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'input_amount' => 'decimal:9',
            'input_amount_usd' => 'decimal:6',
            'output_amount' => 'decimal:9',
            'output_amount_usd' => 'decimal:6',
            'platform_fee_amount' => 'decimal:9',
            'platform_fee_usd' => 'decimal:6',
            'raw_metadata' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }
}
