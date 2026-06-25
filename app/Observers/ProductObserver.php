<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\CacheService;
use Illuminate\Support\Facades\Log;

/**
 * ProductObserver - Handles cache invalidation for Product model
 * 
 * Industry Best Practice: Using Observers decouples cache logic from controllers
 * and ensures cache is ALWAYS invalidated regardless of how the model is modified
 * (via controller, artisan command, queue job, etc.)
 */
class ProductObserver
{
    /**
     * Handle the Product "created" event.
     * 
     * @param Product $product
     * @return void
     */
    public function created(Product $product): void
    {
        $this->clearProductCaches($product, 'created');
    }

    /**
     * Handle the Product "updated" event.
     * 
     * @param Product $product
     * @return void
     */
    public function updated(Product $product): void
    {
        $this->clearProductCaches($product, 'updated');
    }

    /**
     * Handle the Product "restored" event (for soft deletes).
     *
     * @param Product $product
     * @return void
     */
    public function restored(Product $product): void
    {
        $this->clearProductCaches($product, 'restored');
    }

    /**
     * Clear product-related caches
     * 
     * @param Product $product
     * @param string $event
     * @return void
     */
    private function clearProductCaches(Product $product, string $event): void
    {
        try {
            CacheService::clearProductCaches();
            
            Log::debug('Product cache cleared', [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'event' => $event,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to clear product cache', [
                'product_id' => $product->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
