<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * CacheService - Centralized Cache Management
 * 
 * Industry best practices implemented:
 * 1. Cache Tags (for Redis/Memcached) - allows grouped cache invalidation
 * 2. Cache Versioning (for file cache) - increment version to invalidate all related caches
 * 3. Graceful Degradation - works with any cache driver
 * 4. TTL Management - configurable time-to-live
 * 
 * @package App\Services
 */
class CacheService
{
    /**
     * Version keys - incrementing these invalidates all related caches
     */
    public const VERSION_KEY_PRODUCTS = 'cache_version:products';
    public const VERSION_KEY_CATEGORIES = 'cache_version:categories';
    public const VERSION_KEY_BANNERS = 'cache_version:banners';
    public const VERSION_KEY_NAVIGATION = 'cache_version:navigation';
    
    /**
     * Cache prefixes for different domains
     */
    public const PREFIX_PRODUCTS = 'products';
    public const PREFIX_CATEGORIES = 'categories';
    public const PREFIX_MENU = 'menu';
    public const PREFIX_BANNERS = 'banners';
    public const PREFIX_NAVIGATION = 'navigation';
    
    /**
     * Cache tags for grouped invalidation (Redis/Memcached only)
     */
    public const TAG_PRODUCTS = 'products';
    public const TAG_PRODUCT_LIST = 'product_list';
    public const TAG_PRODUCT_FILTERS = 'product_filters';
    public const TAG_CATEGORIES = 'categories';
    public const TAG_MENU = 'menu';
    public const TAG_BANNERS = 'banners';
    public const TAG_NAVIGATION = 'navigation';
    
    /**
     * Default TTL values in seconds
     */
    public const TTL_SHORT = 900;        // 15 minutes - for frequently changing data
    public const TTL_MEDIUM = 3600;      // 1 hour - for product lists
    public const TTL_LONG = 86400;       // 24 hours - for categories, menus
    public const TTL_EXTENDED = 604800;  // 7 days - for static content
    
    /**
     * Check if cache driver supports tags
     */
    public static function supportsTagging(): bool
    {
        try {
            return method_exists(Cache::getStore(), 'tags');
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Get the current cache version for a domain
     * 
     * @param string $versionKey
     * @return int
     */
    public static function getVersion(string $versionKey): int
    {
        return (int) Cache::get($versionKey, 1);
    }
    
    /**
     * Increment the cache version (invalidates all caches with this version)
     * 
     * @param string $versionKey
     * @return int New version number
     */
    public static function incrementVersion(string $versionKey): int
    {
        $newVersion = self::getVersion($versionKey) + 1;
        Cache::forever($versionKey, $newVersion);
        return $newVersion;
    }
    
    /**
     * Generate a versioned cache key
     * This ensures old caches become orphaned when version changes
     * 
     * @param string $baseKey
     * @param string $versionKey
     * @return string
     */
    public static function versionedKey(string $baseKey, string $versionKey): string
    {
        $version = self::getVersion($versionKey);
        return "v{$version}:{$baseKey}";
    }
    
    /**
     * Get versioned key for products
     */
    public static function productKey(string $baseKey): string
    {
        return self::versionedKey($baseKey, self::VERSION_KEY_PRODUCTS);
    }
    
    /**
     * Get versioned key for categories
     */
    public static function categoryKey(string $baseKey): string
    {
        return self::versionedKey($baseKey, self::VERSION_KEY_CATEGORIES);
    }
    
    /**
     * Get versioned key for banners
     */
    public static function bannerKey(string $baseKey): string
    {
        return self::versionedKey($baseKey, self::VERSION_KEY_BANNERS);
    }
    
    /**
     * Remember data with optional tags (for Redis) or versioned keys (for file cache)
     * 
     * @param string $key
     * @param int $ttl
     * @param callable $callback
     * @param array $tags
     * @return mixed
     */
    public static function remember(string $key, int $ttl, callable $callback, array $tags = []): mixed
    {
        try {
            if (!empty($tags) && self::supportsTagging()) {
                return Cache::tags($tags)->remember($key, $ttl, $callback);
            }
            return Cache::remember($key, $ttl, $callback);
        } catch (\Exception $e) {
            Log::warning('Cache remember failed, executing callback directly', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return $callback();
        }
    }
    
    /**
     * Put data with optional tags
     * 
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @param array $tags
     * @return bool
     */
    public static function put(string $key, mixed $value, int $ttl, array $tags = []): bool
    {
        try {
            if (!empty($tags) && self::supportsTagging()) {
                return Cache::tags($tags)->put($key, $value, $ttl);
            }
            return Cache::put($key, $value, $ttl);
        } catch (\Exception $e) {
            Log::warning('Cache put failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Get data with optional tags
     * 
     * @param string $key
     * @param array $tags
     * @return mixed
     */
    public static function get(string $key, array $tags = []): mixed
    {
        try {
            if (!empty($tags) && self::supportsTagging()) {
                return Cache::tags($tags)->get($key);
            }
            return Cache::get($key);
        } catch (\Exception $e) {
            Log::warning('Cache get failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Forget a specific key
     * 
     * @param string $key
     * @param array $tags
     * @return bool
     */
    public static function forget(string $key, array $tags = []): bool
    {
        try {
            if (!empty($tags) && self::supportsTagging()) {
                return Cache::tags($tags)->forget($key);
            }
            return Cache::forget($key);
        } catch (\Exception $e) {
            Log::warning('Cache forget failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    // =========================================================================
    // Domain-Specific Cache Invalidation Methods
    // =========================================================================
    
    /**
     * Clear all product-related caches
     * Call this when a product is created, updated, or deleted
     * 
     * For Redis: Flushes by tags
     * For File Cache: Increments version (old caches become orphaned and expire naturally)
     * 
     * @return void
     */
    public static function clearProductCaches(): void
    {
        try {
            if (self::supportsTagging()) {
                // Redis/Memcached: flush by tags
                Cache::tags([
                    self::TAG_PRODUCTS,
                    self::TAG_PRODUCT_LIST,
                    self::TAG_PRODUCT_FILTERS,
                ])->flush();
            }
            
            // Always increment version (works for both Redis and file cache)
            // This invalidates all versioned product cache keys
            $newVersion = self::incrementVersion(self::VERSION_KEY_PRODUCTS);
            
            // Also clear navigation caches as they depend on products
            self::clearNavigationCaches();
            
            Log::info('Product caches cleared', ['new_version' => $newVersion]);
        } catch (\Exception $e) {
            Log::error('Failed to clear product caches', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Clear all category-related caches
     * Call this when a category is created, updated, or deleted
     * 
     * @return void
     */
    public static function clearCategoryCaches(): void
    {
        try {
            if (self::supportsTagging()) {
                Cache::tags([
                    self::TAG_CATEGORIES,
                    self::TAG_MENU,
                ])->flush();
            }
            
            // Increment category version
            $newVersion = self::incrementVersion(self::VERSION_KEY_CATEGORIES);
            
            // Also clear specific category cache keys
            Cache::forget('categories:tree');
            Cache::forget('categories:root');
            Cache::forget('categories:menu');
            
            // Also clear product caches since products depend on categories
            self::clearProductCaches();
            
            Log::info('Category caches cleared', ['new_version' => $newVersion]);
        } catch (\Exception $e) {
            Log::error('Failed to clear category caches', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Clear all menu caches
     * 
     * @return void
     */
    public static function clearMenuCaches(): void
    {
        try {
            if (self::supportsTagging()) {
                Cache::tags([self::TAG_MENU])->flush();
            }
            
            Cache::forget('categories:menu');
            
            Log::info('Menu caches cleared');
        } catch (\Exception $e) {
            Log::error('Failed to clear menu caches', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Clear all banner caches
     * 
     * @return void
     */
    public static function clearBannerCaches(): void
    {
        try {
            if (self::supportsTagging()) {
                Cache::tags([self::TAG_BANNERS])->flush();
            }
            
            $newVersion = self::incrementVersion(self::VERSION_KEY_BANNERS);
            Cache::forget('banner_sliders_data');
            
            Log::info('Banner caches cleared', ['new_version' => $newVersion]);
        } catch (\Exception $e) {
            Log::error('Failed to clear banner caches', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Clear all navigation caches
     * 
     * @return void
     */
    public static function clearNavigationCaches(): void
    {
        try {
            if (self::supportsTagging()) {
                Cache::tags([self::TAG_NAVIGATION])->flush();
            }
            
            $newVersion = self::incrementVersion(self::VERSION_KEY_NAVIGATION);
            
            Log::info('Navigation caches cleared', ['new_version' => $newVersion]);
        } catch (\Exception $e) {
            Log::error('Failed to clear navigation caches', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Clear ALL application caches
     * Use with caution - only for maintenance/deployment
     * 
     * @return void
     */
    public static function clearAllCaches(): void
    {
        try {
            // Increment all versions
            self::incrementVersion(self::VERSION_KEY_PRODUCTS);
            self::incrementVersion(self::VERSION_KEY_CATEGORIES);
            self::incrementVersion(self::VERSION_KEY_BANNERS);
            self::incrementVersion(self::VERSION_KEY_NAVIGATION);
            
            if (self::supportsTagging()) {
                Cache::tags([
                    self::TAG_PRODUCTS,
                    self::TAG_PRODUCT_LIST,
                    self::TAG_PRODUCT_FILTERS,
                    self::TAG_CATEGORIES,
                    self::TAG_MENU,
                    self::TAG_BANNERS,
                    self::TAG_NAVIGATION,
                ])->flush();
            }
            
            // Clear known static keys
            Cache::forget('categories:tree');
            Cache::forget('categories:root');
            Cache::forget('categories:menu');
            Cache::forget('banner_sliders_data');
            
            Log::info('All application caches cleared');
        } catch (\Exception $e) {
            Log::error('Failed to clear all caches', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Warm up critical caches after clearing
     * Call this after deployment or cache clear
     * 
     * @return void
     */
    public static function warmUp(): void
    {
        try {
            // Warm up category menu
            app(CategoryService::class)->getTree(true);
            app(CategoryService::class)->getMenu(true);
            
            // Warm up banner sliders
            app(BannerSliderService::class)->getActiveSliders(true);
            
            // Warm up featured products (most accessed)
            app(ProductService::class)->getFeaturedProducts(20, true);
            
            Log::info('Cache warm-up completed');
        } catch (\Exception $e) {
            Log::error('Cache warm-up failed', ['error' => $e->getMessage()]);
        }
    }
}
