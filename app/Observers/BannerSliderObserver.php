<?php

namespace App\Observers;

use App\Models\BannerSlider;
use App\Services\CacheService;
use Illuminate\Support\Facades\Log;

/**
 * BannerSliderObserver - Handles cache invalidation for BannerSlider model
 */
class BannerSliderObserver
{
    /**
     * Handle the BannerSlider "created" event.
     */
    public function created(BannerSlider $bannerSlider): void
    {
        $this->clearBannerCaches($bannerSlider, 'created');
    }

    /**
     * Handle the BannerSlider "updated" event.
     */
    public function updated(BannerSlider $bannerSlider): void
    {
        $this->clearBannerCaches($bannerSlider, 'updated');
    }

    /**
     * Handle the BannerSlider "deleted" event.
     */
    public function deleted(BannerSlider $bannerSlider): void
    {
        $this->clearBannerCaches($bannerSlider, 'deleted');
    }

    /**
     * Clear banner-related caches
     */
    private function clearBannerCaches(BannerSlider $bannerSlider, string $event): void
    {
        try {
            CacheService::clearBannerCaches();
            
            Log::debug('Banner cache cleared', [
                'banner_id' => $bannerSlider->id,
                'banner_name' => $bannerSlider->name,
                'event' => $event,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear banner cache', [
                'banner_id' => $bannerSlider->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
