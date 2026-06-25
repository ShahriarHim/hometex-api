<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'label', 'description'];

    private const CACHE_PREFIX = 'sys_setting:';
    private const CACHE_ALL    = 'sys_settings:all';
    // 24-hour TTL — settings change rarely; invalidated on every write
    private const CACHE_TTL    = 86400;

    /**
     * Get a typed setting value. Returns $default if key doesn't exist.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = self::CACHE_PREFIX . $key;

        $row = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key) {
            return self::where('key', $key)->first();
        });

        if (! $row) {
            return $default;
        }

        return self::cast($row->value, $row->type);
    }

    /**
     * Set a setting value and invalidate cache.
     */
    public static function set(string $key, mixed $value): void
    {
        self::where('key', $key)->update(['value' => (string) $value]);
        Cache::forget(self::CACHE_PREFIX . $key);
        Cache::forget(self::CACHE_ALL);
    }

    /**
     * Return all settings, grouped, with cache.
     */
    public static function allGrouped(): array
    {
        return Cache::remember(self::CACHE_ALL, self::CACHE_TTL, function () {
            return self::orderBy('group')->orderBy('id')->get()
                ->groupBy('group')
                ->map(fn ($group) => $group->map(fn ($row) => [
                    'key'         => $row->key,
                    'value'       => self::cast($row->value, $row->type),
                    'type'        => $row->type,
                    'label'       => $row->label,
                    'description' => $row->description,
                ])->values())
                ->toArray();
        });
    }

    /**
     * Flush all setting caches. Call after bulk updates.
     * Only clears setting-prefixed keys — never flushes the full cache store.
     */
    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_ALL);

        $keys = self::pluck('key');
        foreach ($keys as $key) {
            Cache::forget(self::CACHE_PREFIX . $key);
        }
    }

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => (bool) (int) $value,
            'json'    => json_decode($value, true),
            default   => (string) $value,
        };
    }
}
