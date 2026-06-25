<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BannerConfig extends Model
{
    protected $table = 'banner_config';

    private const CACHE_KEY = 'banner_config';
    private const CACHE_TTL = 86400; // 24h — changes rarely

    protected $fillable = [
        'autoplay', 'autoplay_delay_ms', 'transition', 'show_dots', 'show_arrows',
    ];

    protected $casts = [
        'autoplay'    => 'boolean',
        'show_dots'   => 'boolean',
        'show_arrows' => 'boolean',
    ];

    public static function current(): self
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => self::firstOrFail());
    }

    public static function updateConfig(array $data): self
    {
        $config = self::firstOrFail();
        $config->update($data);
        Cache::forget(self::CACHE_KEY);
        return $config->fresh();
    }
}
