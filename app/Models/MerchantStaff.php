<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantStaff extends Model
{
    use HasFactory;

    protected $table = 'merchant_staff';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_CASHIER = 'cashier';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'merchant_id',
        'user_id',
        'role',
        'status',
        'default_location_id',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(StoreLocation::class, 'default_location_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function canManageStaff(): bool
    {
        return $this->isActive() && $this->role === self::ROLE_MANAGER;
    }

    public function canSell(): bool
    {
        return $this->isActive() && in_array($this->role, [self::ROLE_MANAGER, self::ROLE_CASHIER], true);
    }
}
