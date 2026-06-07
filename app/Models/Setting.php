<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, $default = null)
    {
        $cacheKey = 'setting:' . $key;
        return Cache::remember($cacheKey, 300, function () use ($key, $default) {
            $row = self::where('key', $key)->first();
            return $row ? $row->value : $default;
        });
    }

    public static function setValue(string $key, $value): void
    {
        self::updateOrCreate(
            ['key' => $key],
            ['value' => is_string($value) ? $value : json_encode($value)]
        );
        Cache::forget('setting:' . $key);
    }
}
