<?php

namespace App\Providers;

use App\Models\BannerSlider;
use App\Models\Category;
use App\Models\Product;
use App\Observers\BannerSliderObserver;
use App\Observers\CategoryObserver;
use App\Observers\ProductObserver;
use App\Services\Category\CategoryCommandService;
use App\Services\Category\CategoryQueryService;
use App\Services\CategoryService;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CategoryQueryService::class);
        $this->app->singleton(CategoryCommandService::class);
        $this->app->singleton(CategoryService::class);
    }

    public function boot(): void
    {
        Product::observe(ProductObserver::class);
        Category::observe(CategoryObserver::class);
        BannerSlider::observe(BannerSliderObserver::class);

        LogViewer::auth(function ($request) {
            return true;
        });
    }
}
