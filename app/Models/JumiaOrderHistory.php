<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JumiaOrderHistory extends Model
{
    use HasFactory;

    protected $table = 'jumia_order_history';

    protected $fillable = [
        'jumia_order_id',
        'status',
        'status_description',
        'timestamp',
        'notes',
        'updated_by',
        'external_reference'
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(JumiaOrder::class, 'jumia_order_id');
    }
}
