<?php

namespace App\Providers;

use App\Models\Category;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureModelBindings();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure custom route model bindings for unified Category model
     */
    protected function configureModelBindings(): void
    {
        // Bind 'subCategory' parameter to Category model (for level 2 subcategories)
        Route::bind('subCategory', function ($value) {
            return Category::findOrFail($value);
        });

        // Bind 'childSubCategory' parameter to Category model (for level 3 child categories)
        Route::bind('childSubCategory', function ($value) {
            return Category::findOrFail($value);
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // General API rate limit - increased for better UX
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Higher limit for product listing (public, cacheable)
        RateLimiter::for('products', function (Request $request) {
            return Limit::perMinute(200)->by($request->ip());
        });

        // Strict limit for authenticated actions
        RateLimiter::for('authenticated', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
