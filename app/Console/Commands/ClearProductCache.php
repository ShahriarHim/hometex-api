<?php

namespace App\Console\Commands;

use App\Services\CacheService;
use Illuminate\Console\Command;

class ClearProductCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:clear-app 
                            {--products : Clear only product caches}
                            {--categories : Clear only category/menu caches}
                            {--banners : Clear only banner caches}
                            {--all : Clear all application caches}
                            {--warm : Warm up caches after clearing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear application caches (products, categories, menus, banners)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🧹 Clearing application caches...');
        $this->newLine();

        $cleared = false;

        if ($this->option('products')) {
            CacheService::clearProductCaches();
            $this->info('✓ Product caches cleared');
            $cleared = true;
        }

        if ($this->option('categories')) {
            CacheService::clearCategoryCaches();
            $this->info('✓ Category and menu caches cleared');
            $cleared = true;
        }

        if ($this->option('banners')) {
            CacheService::clearBannerCaches();
            $this->info('✓ Banner caches cleared');
            $cleared = true;
        }

        if ($this->option('all') || !$cleared) {
            CacheService::clearAllCaches();
            $this->info('✓ All application caches cleared');
        }

        if ($this->option('warm')) {
            $this->newLine();
            $this->info('🔥 Warming up caches...');
            CacheService::warmUp();
            $this->info('✓ Cache warm-up completed');
        }

        $this->newLine();
        $this->info('✅ Cache operation completed successfully!');
        
        return Command::SUCCESS;
    }
}
