<?php

namespace App\Observers;

use App\Models\Category;
use App\Services\CacheService;
use Illuminate\Support\Facades\Log;

/**
 * CategoryObserver - Handles cache invalidation for Category model
 * 
 * This ensures menu and category tree caches are always up-to-date
 * when categories are modified.
 */
class CategoryObserver
{
    /**
     * Handle the Category "created" event.
     * 
     * @param Category $category
     * @return void
     */
    public function created(Category $category): void
    {
        $this->clearCategoryCaches($category, 'created');
    }

    /**
     * Handle the Category "updated" event.
     * 
     * @param Category $category
     * @return void
     */
    public function updated(Category $category): void
    {
        $this->clearCategoryCaches($category, 'updated');
    }

    /**
     * Handle the Category "deleted" event.
     * 
     * @param Category $category
     * @return void
     */
    public function deleted(Category $category): void
    {
        $this->clearCategoryCaches($category, 'deleted');
    }

    /**
     * Handle the Category "restored" event.
     * 
     * @param Category $category
     * @return void
     */
    public function restored(Category $category): void
    {
        $this->clearCategoryCaches($category, 'restored');
    }

    /**
     * Handle the Category "force deleted" event.
     * 
     * @param Category $category
     * @return void
     */
    public function forceDeleted(Category $category): void
    {
        $this->clearCategoryCaches($category, 'force_deleted');
    }

    /**
     * Clear category-related caches
     * 
     * @param Category $category
     * @param string $event
     * @return void
     */
    private function clearCategoryCaches(Category $category, string $event): void
    {
        try {
            CacheService::clearCategoryCaches();
            
            Log::debug('Category cache cleared', [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'event' => $event,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear category cache', [
                'category_id' => $category->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
