<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class OlBotSetting extends Model
{
    protected $table = 'ol_bot_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @param  string  $key
     * @param  string|null  $default
     */
    public static function getValue(string $key, ?string $default = null): ?string
    {
        $cacheKey = self::cacheKey($key);
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);

            return (is_string($cached) && $cached !== '') ? $cached : $default;
        }

        $value = static::query()->where('key', $key)->value('value');
        $resolved = (is_string($value) && $value !== '') ? $value : $default;
        Cache::put($cacheKey, $resolved, 30);

        return $resolved;
    }

    /**
     * @param  string  $key
     * @param  string|null  $value
     */
    public static function setValue(string $key, ?string $value): void
    {
        static::query()->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now(), 'created_at' => now()],
        );
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'ol_bot_setting:'.$key;
    }
}
