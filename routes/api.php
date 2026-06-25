<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AreaController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\AttributeController;
use App\Http\Controllers\AttributeValueController;
use App\Http\Controllers\BannerSliderController;
use App\Http\Controllers\ChildSubCategoryController;
use App\Http\Controllers\CsvController;
use App\Http\Controllers\FormulaController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\DivisionController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentGatewayController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductReviewController;
use App\Http\Controllers\ProductPhotoController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SubCategoryController;
use App\Http\Controllers\AdjustmentController;
use App\Http\Controllers\ProductTransferController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\ShopQuantityController;
use App\Http\Controllers\BannerConfigController;
use App\Http\Controllers\CorporateManagementController;
use App\Http\Controllers\web_api\CheckOutController;
use App\Http\Controllers\web_api\CategoryApiController;
use App\Http\Controllers\web_api\EcomUserController;
use App\Http\Controllers\web_api\GuestCheckoutController;
use App\Http\Controllers\web_api\OrderDetailsController;
use App\Http\Controllers\web_api\PaymentController;
use App\Http\Controllers\web_api\WishListController;
use App\Http\Controllers\web_api\CorporateAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Namespace conventions:
|   /api/           → IMS (admin/staff) — auth:sanctum + role middleware
|   /api/v1/        → ECOM public — no auth
|   /api/v1/ecom/   → ECOM customer auth — requires auth:sanctum
|
| IMS middleware stack:
|   admin_or_staff  → any IMS role (view operations)
|   permission:x    → specific action gate on top of role check
|   admin           → admin-only (staff/role management)
*/

// =========================================================================
// HEALTH
// =========================================================================

Route::get('/ping', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toISOString()]));

// =========================================================================
// AUTH (IMS)
// =========================================================================

Route::post('login', [AuthController::class, 'login'])->name('login');

// =========================================================================
// PUBLIC — ECOM STOREFRONT (no auth, read-only)
// =========================================================================

Route::prefix('v1/categories')->group(function () {
    Route::get('/tree', [CategoryApiController::class, 'tree']);
    Route::get('/slug/{slug}', [CategoryApiController::class, 'showBySlug']);
    Route::get('/{id}/children', [CategoryApiController::class, 'children']);
    Route::get('/{id}/breadcrumb', [CategoryApiController::class, 'breadcrumb']);
    Route::get('/', [CategoryApiController::class, 'index']);
});

Route::prefix('products')->group(function () {
    Route::get('featured', [ProductController::class, 'featured']);
    Route::get('new-arrivals', [ProductController::class, 'newArrivals']);
    Route::get('trending', [ProductController::class, 'trending']);
    Route::get('bestsellers', [ProductController::class, 'bestsellers']);
    Route::get('on-sale', [ProductController::class, 'onSale']);
    Route::get('category/{categoryId}', [ProductController::class, 'byCategory']);
    Route::get('brand/{brandId}', [ProductController::class, 'byBrand']);
    Route::get('slug/{slug}', [ProductController::class, 'showBySlug'])->name('products.show.slug');
    Route::get('{id}/navigation', [ProductController::class, 'navigation']);
    Route::get('{id}/similar', [ProductController::class, 'similar']);
    Route::get('{id}/recommendations', [ProductController::class, 'recommendations']);
    Route::get('{id}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/', [ProductController::class, 'index']);
});

Route::get('products/{productId}/reviews', [ProductReviewController::class, 'getByProduct']);
Route::get('hero-banners', [BannerSliderController::class, 'index']);
Route::get('division', [DivisionController::class, 'index']);
Route::get('districts/{division_id}', [DistrictController::class, 'index']);
Route::get('area/{division_key}', [AreaController::class, 'index']);
Route::get('shops', [ShopController::class, 'getShops']);
Route::get('shops/{shop_id}', [ShopController::class, 'getShopProducts']);

// =========================================================================
// ECOM — Customer auth
// =========================================================================

Route::post('customer-signup', [EcomUserController::class, 'registration']);
Route::post('customer-login', [EcomUserController::class, 'UserLogin']);
Route::post('customer-google-login', [EcomUserController::class, 'googleLogin']);
Route::post('corporate-register', [CorporateAuthController::class, 'register']);
Route::post('corporate-login', [CorporateAuthController::class, 'login']);

Route::prefix('guest')->group(function () {
    Route::post('checkout', [GuestCheckoutController::class, 'checkout']);
    Route::get('orders/track', [GuestCheckoutController::class, 'trackOrder']);
    Route::post('orders/lookup', [GuestCheckoutController::class, 'getOrderByEmailAndNumber']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('my-profile', [EcomUserController::class, 'myprofile']);
    Route::post('my-profile-update', [EcomUserController::class, 'updateprofile']);
    Route::post('customer-logout', [EcomUserController::class, 'logout']);
    Route::get('my-order', [OrderDetailsController::class, 'myorder']);

    Route::get('corporate-profile', [CorporateAuthController::class, 'profile']);
    Route::put('corporate-profile', [CorporateAuthController::class, 'updateProfile']);
    Route::post('corporate-logout', [CorporateAuthController::class, 'logout']);

    Route::post('wish-list', [WishListController::class, 'wishlist']);
    Route::post('get-wish-list', [WishListController::class, 'getWishlist']);
    Route::post('delete-wish-list', [WishListController::class, 'deleteWishlist']);

    Route::post('store-review', [ProductReviewController::class, 'store']);
    Route::put('update-review/{id}', [ProductReviewController::class, 'update']);
    Route::delete('delete-review/{id}', [ProductReviewController::class, 'destroy']);

    Route::post('check-out-logein-user', [CheckOutController::class, 'checkoutbyloginuser']);
});

Route::post('check-out', [CheckOutController::class, 'checkout']);

Route::get('orders/invoice/{invoiceId}', [OrderController::class, 'getOrderByInvoice'])->where('invoiceId', '.*');
Route::get('orders/tracking', [OrderController::class, 'getTrackingStatus']);
Route::get('orders/customer/{user_id}', [OrderController::class, 'getOrdersByCustomer']);

Route::get('get-payment-details', [PaymentController::class, 'getpaymentdetails']);
Route::post('payment-success', [PaymentController::class, 'paymentsuccess']);
Route::get('payment-cancel', [PaymentController::class, 'paymentcancel']);
Route::get('payment-fail', [PaymentController::class, 'paymentfail']);
Route::get('get-token', [PaymentGatewayController::class, 'getToken']);

// =========================================================================
// IMS — Any staff role
// =========================================================================

Route::middleware('admin_or_staff')->group(function () {

    // Auth + profile
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me/profile', [AuthController::class, 'updateProfile']);
    Route::post('logout', [AuthController::class, 'logout']);

    // Dropdown lists (read — all roles)
    Route::get('get-attribute-list', [AttributeController::class, 'get_attribute_list']);
    Route::get('get-supplier-list', [SupplierController::class, 'get_provider_list']);
    Route::get('get-country-list', [CountryController::class, 'get_country_list']);
    Route::get('get-brand-list', [BrandController::class, 'get_brand_list']);
    Route::get('get-category-list', [CategoryController::class, 'get_category_list']);
    Route::get('get-sub-category-list', [SubCategoryController::class, 'get_sub_category_list_fc']);
    Route::get('get-sub-category-list/{category_id}', [SubCategoryController::class, 'get_sub_category_list']);
    Route::get('get-child-sub-category-list', [ChildSubCategoryController::class, 'get_child_sub_category_list']);
    Route::get('get-child-sub-category-list/{category_id}', [ChildSubCategoryController::class, 'get_child_sub_category_list']);
    Route::get('get-shop-list', [ShopController::class, 'get_shop_list']);
    Route::get('get-payment-methods', [PaymentMethodController::class, 'index']);
    Route::get('get-add-product-data', [ProductController::class, 'get_add_product_data']);

    // -----------------------------------------------------------------------
    // Products
    // -----------------------------------------------------------------------
    Route::get('product', [ProductController::class, 'index']);
    Route::get('product/{product}', [ProductController::class, 'show']);

    // -----------------------------------------------------------------------
    // Stock Analytics — requires analytics.view permission
    // -----------------------------------------------------------------------
    Route::middleware('permission:analytics.view')->group(function () {
        Route::get('analytics/products', [\App\Http\Controllers\StockAnalyticsController::class, 'productRankings']);
        Route::get('analytics/products/{id}', [\App\Http\Controllers\StockAnalyticsController::class, 'productDetail']);
    });
    Route::get('get-product-columns', [ProductController::class, 'get_product_columns']);
    Route::get('get-product-list-for-bar-code', [ProductController::class, 'get_product_list_for_bar_code']);

    Route::post('product', [ProductController::class, 'store'])->middleware('permission:products.create');
    Route::put('product/{product}', [ProductController::class, 'update'])->middleware('permission:products.edit');
    Route::patch('product/{product}', [ProductController::class, 'update'])->middleware('permission:products.edit');
    Route::delete('product/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete');
    Route::post('product/{id}/photos', [ProductPhotoController::class, 'store'])->middleware('permission:products.edit');
    Route::post('product/{id}/duplicate', [ProductController::class, 'duplicate'])->middleware('permission:products.create');
    Route::post('/save-csv', [CsvController::class, 'saveCsv'])->middleware('permission:products.import');

    Route::get('photo/{photo}', [ProductPhotoController::class, 'show']);
    Route::put('photo/{photo}', [ProductPhotoController::class, 'update'])->middleware('permission:products.edit');
    Route::delete('photo/{photo}', [ProductPhotoController::class, 'destroy'])->middleware('permission:products.edit');
    Route::put('photo/{photo}/primary', [ProductPhotoController::class, 'setPrimary'])->middleware('permission:products.edit');
    Route::put('product/{productId}/photos/reorder', [ProductPhotoController::class, 'reorder'])->middleware('permission:products.edit');

    // -----------------------------------------------------------------------
    // Catalog — brands, categories, sub-categories, child sub-categories
    // -----------------------------------------------------------------------
    Route::get('brand', [BrandController::class, 'index']);
    Route::get('brand/{brand}', [BrandController::class, 'show']);
    Route::post('brand', [BrandController::class, 'store'])->middleware('permission:catalog.create');
    Route::put('brand/{brand}', [BrandController::class, 'update'])->middleware('permission:catalog.edit');
    Route::patch('brand/{brand}', [BrandController::class, 'update'])->middleware('permission:catalog.edit');
    Route::delete('brand/{brand}', [BrandController::class, 'destroy'])->middleware('permission:catalog.delete');

    Route::get('category', [CategoryController::class, 'index']);
    Route::get('category/{category}', [CategoryController::class, 'show']);
    Route::post('category', [CategoryController::class, 'store'])->middleware('permission:catalog.create');
    Route::put('category/{category}', [CategoryController::class, 'update'])->middleware('permission:catalog.edit');
    Route::patch('category/{category}', [CategoryController::class, 'update'])->middleware('permission:catalog.edit');
    Route::delete('category/{category}', [CategoryController::class, 'destroy'])->middleware('permission:catalog.delete');

    Route::get('sub-category', [SubCategoryController::class, 'index']);
    Route::get('sub-category/{subCategory}', [SubCategoryController::class, 'show']);
    Route::post('sub-category', [SubCategoryController::class, 'store'])->middleware('permission:catalog.create');
    Route::put('sub-category/{subCategory}', [SubCategoryController::class, 'update'])->middleware('permission:catalog.edit');
    Route::patch('sub-category/{subCategory}', [SubCategoryController::class, 'update'])->middleware('permission:catalog.edit');
    Route::delete('sub-category/{subCategory}', [SubCategoryController::class, 'destroy'])->middleware('permission:catalog.delete');

    Route::get('child-sub-category', [ChildSubCategoryController::class, 'index']);
    Route::get('child-sub-category/{childSubCategory}', [ChildSubCategoryController::class, 'show']);
    Route::post('child-sub-category', [ChildSubCategoryController::class, 'store'])->middleware('permission:catalog.create');
    Route::put('child-sub-category/{childSubCategory}', [ChildSubCategoryController::class, 'update'])->middleware('permission:catalog.edit');
    Route::patch('child-sub-category/{childSubCategory}', [ChildSubCategoryController::class, 'update'])->middleware('permission:catalog.edit');
    Route::delete('child-sub-category/{childSubCategory}', [ChildSubCategoryController::class, 'destroy'])->middleware('permission:catalog.delete');

    // -----------------------------------------------------------------------
    // Attributes & Pricing
    // -----------------------------------------------------------------------
    Route::get('attribute', [AttributeController::class, 'index']);
    Route::get('attribute/{attribute}', [AttributeController::class, 'show']);
    Route::post('attribute', [AttributeController::class, 'store'])->middleware('permission:attributes.manage');
    Route::put('attribute/{attribute}', [AttributeController::class, 'update'])->middleware('permission:attributes.manage');
    Route::patch('attribute/{attribute}', [AttributeController::class, 'update'])->middleware('permission:attributes.manage');
    Route::delete('attribute/{attribute}', [AttributeController::class, 'destroy'])->middleware('permission:attributes.manage');

    Route::get('attribute-value', [AttributeValueController::class, 'index']);
    Route::get('attribute-value/{attributeValue}', [AttributeValueController::class, 'show']);
    Route::post('attribute-value', [AttributeValueController::class, 'store'])->middleware('permission:attributes.manage');
    Route::put('attribute-value/{attributeValue}', [AttributeValueController::class, 'update'])->middleware('permission:attributes.manage');
    Route::patch('attribute-value/{attributeValue}', [AttributeValueController::class, 'update'])->middleware('permission:attributes.manage');
    Route::delete('attribute-value/{attributeValue}', [AttributeValueController::class, 'destroy'])->middleware('permission:attributes.manage');

    Route::get('formula', [FormulaController::class, 'index']);
    Route::get('formula/{formula}', [FormulaController::class, 'show']);
    Route::post('formula', [FormulaController::class, 'store'])->middleware('permission:pricing.manage');
    Route::put('formula/{formula}', [FormulaController::class, 'update'])->middleware('permission:pricing.manage');
    Route::patch('formula/{formula}', [FormulaController::class, 'update'])->middleware('permission:pricing.manage');
    Route::delete('formula/{formula}', [FormulaController::class, 'destroy'])->middleware('permission:pricing.manage');

    // -----------------------------------------------------------------------
    // Suppliers
    // -----------------------------------------------------------------------
    Route::get('supplier', [SupplierController::class, 'index']);
    Route::get('supplier/{supplier}', [SupplierController::class, 'show']);
    Route::post('supplier', [SupplierController::class, 'store'])->middleware('permission:suppliers.create');
    Route::put('supplier/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.edit');
    Route::patch('supplier/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.edit');
    Route::delete('supplier/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:suppliers.delete');

    // -----------------------------------------------------------------------
    // Shops / Branches
    // -----------------------------------------------------------------------
    Route::get('shop', [ShopController::class, 'index']);
    Route::get('shop/{shop}', [ShopController::class, 'show']);
    Route::post('shop', [ShopController::class, 'store'])->middleware('permission:shops.create');
    Route::put('shop/{shop}', [ShopController::class, 'update'])->middleware('permission:shops.edit');
    Route::patch('shop/{shop}', [ShopController::class, 'update'])->middleware('permission:shops.edit');
    Route::delete('shop/{shop}', [ShopController::class, 'destroy'])->middleware('permission:shops.delete');

    // -----------------------------------------------------------------------
    // Staff management
    // -----------------------------------------------------------------------
    Route::get('staff', [StaffController::class, 'index'])->middleware('permission:staff.view');
    Route::get('staff/{staff}', [StaffController::class, 'show'])->middleware('permission:staff.view');
    Route::post('staff', [StaffController::class, 'store'])->middleware('permission:staff.create');
    Route::put('staff/{staff}', [StaffController::class, 'update'])->middleware('permission:staff.edit');
    Route::patch('staff/{staff}', [StaffController::class, 'update'])->middleware('permission:staff.edit');
    Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->middleware('permission:staff.delete');

    // -----------------------------------------------------------------------
    // Orders (IMS / POS)
    // -----------------------------------------------------------------------
    Route::get('order', [OrderController::class, 'index'])->middleware('permission:orders.view');
    Route::get('order/{order}', [OrderController::class, 'show'])->middleware('permission:orders.view');
    Route::get('order-pending-adjustment-count', [OrderController::class, 'pendingAdjustmentCount'])->middleware('permission:orders.view');
    Route::post('order', [OrderController::class, 'store'])->middleware('permission:orders.create');
    Route::put('order/{order}', [OrderController::class, 'update'])->middleware('permission:orders.edit');
    Route::patch('order/{order}', [OrderController::class, 'update'])->middleware('permission:orders.edit');
    Route::delete('order/{order}', [OrderController::class, 'destroy'])->middleware('permission:orders.cancel');
    Route::post('order/{orderId}/mark-adjusted', [OrderController::class, 'markAdjusted'])->middleware('permission:orders.edit');
    Route::put('order/{order}/payment', [OrderController::class, 'updatePayment'])->middleware('permission:orders.edit');
    Route::put('order/{order}/address', [OrderController::class, 'updateAddress'])->middleware('permission:orders.edit');
    Route::post('order/{order}/items', [OrderController::class, 'addItem'])->middleware('permission:orders.edit');
    Route::put('order/{order}/items/{orderDetail}', [OrderController::class, 'updateItem'])->middleware('permission:orders.edit');
    Route::delete('order/{order}/items/{orderDetail}', [OrderController::class, 'removeItem'])->middleware('permission:orders.edit');
    Route::post('order/{order}/cancel', [OrderController::class, 'cancelOrder'])->middleware('permission:orders.cancel');

    // -----------------------------------------------------------------------
    // Store orders (POS / in-store)
    // -----------------------------------------------------------------------
    Route::get('storecustomer', [\App\Http\Controllers\StoreOrderController::class, 'index'])->middleware('permission:store_orders.view');
    Route::get('storecustomer/{id}', [\App\Http\Controllers\StoreOrderController::class, 'show'])->middleware('permission:store_orders.view');
    Route::post('storecustomer', [\App\Http\Controllers\StoreOrderController::class, 'store'])->middleware('permission:orders.create');
    Route::put('storecustomer/{id}', [\App\Http\Controllers\StoreOrderController::class, 'update'])->middleware('permission:orders.edit');
    Route::patch('storecustomer/{id}', [\App\Http\Controllers\StoreOrderController::class, 'update'])->middleware('permission:orders.edit');
    Route::delete('storecustomer/{id}', [\App\Http\Controllers\StoreOrderController::class, 'destroy'])->middleware('permission:store_orders.cancel');
    Route::post('storecustomer/{id}/cancel', [\App\Http\Controllers\StoreOrderController::class, 'cancel'])->middleware('permission:store_orders.cancel');

    // -----------------------------------------------------------------------
    // Customers
    // -----------------------------------------------------------------------
    Route::get('customer', [CustomerController::class, 'index'])->middleware('permission:customers.view');
    Route::get('customer/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
    Route::get('customer/{customer}/orders', [CustomerController::class, 'orders'])->middleware('permission:customers.view');
    Route::post('customer', [CustomerController::class, 'store'])->middleware('permission:customers.create');
    Route::put('customer/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.edit');
    Route::patch('customer/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.edit');
    Route::delete('customer/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete');

    // -----------------------------------------------------------------------
    // Inventory
    // -----------------------------------------------------------------------
    Route::prefix('transfers')->group(function () {
        Route::get('/', [ProductTransferController::class, 'index']);
        Route::get('/{transfer}', [ProductTransferController::class, 'show']);
        Route::post('/', [ProductTransferController::class, 'store'])->middleware('permission:inventory.transfer.create');
        Route::put('/{transfer}/approve', [ProductTransferController::class, 'approve'])->middleware('permission:inventory.transfer.approve');
        Route::put('/{transfer}/reject', [ProductTransferController::class, 'reject'])->middleware('permission:inventory.transfer.approve');
    });

    Route::post('adjustments', [AdjustmentController::class, 'store'])->middleware('permission:inventory.adjust');

    Route::prefix('shop-quantity')->group(function () {
        Route::get('stock', [ShopQuantityController::class, 'stock']);
        Route::get('product-shops', [ShopQuantityController::class, 'productShops']);
        Route::get('transactions', [ShopQuantityController::class, 'transactions']);
        Route::post('reduce', [ShopQuantityController::class, 'reduce'])->middleware('permission:inventory.adjust');
    });

    // -----------------------------------------------------------------------
    // Returns
    // -----------------------------------------------------------------------
    Route::prefix('returns')->group(function () {
        Route::get('/', [ReturnController::class, 'index'])->middleware('permission:returns.view');
        Route::get('search', [ReturnController::class, 'searchOrders'])->middleware('permission:returns.view');
        Route::get('order-details', [ReturnController::class, 'getOrderDetails'])->middleware('permission:returns.view');
        Route::post('submit', [ReturnController::class, 'submitReturn'])->middleware('permission:returns.process');
    });

    // -----------------------------------------------------------------------
    // Reports
    // -----------------------------------------------------------------------
    Route::middleware('permission:reports.view')->group(function () {
        Route::get('dashboard/sales-trend', [ReportController::class, 'salesTrend']);
        Route::get('dashboard/order-status', [ReportController::class, 'orderStatus']);
        Route::get('dashboard/top-products', [ReportController::class, 'topProducts']);
        Route::get('get-reports', [ReportController::class, 'index']);
        Route::get('get-reports/monthly-sales', [ReportController::class, 'monthlySales']);
        Route::get('get-reports/monthly-purchase', [ReportController::class, 'monthlyPurchase']);
        Route::get('get-reports/sales-today-by-branch', [ReportController::class, 'salesTodayByBranch']);
        Route::get('get-reports/shop-stock-summary', [ReportController::class, 'shopStockSummary']);
        Route::get('get-reports/low-stock-detail', [ReportController::class, 'lowStockDetail']);
    });

    // -----------------------------------------------------------------------
    // Corporate approvals
    // -----------------------------------------------------------------------
    Route::prefix('corporate')->group(function () {
        Route::get('/', [CorporateManagementController::class, 'index'])->middleware('permission:approvals.view');
        Route::get('/pending', [CorporateManagementController::class, 'pending'])->middleware('permission:approvals.view');
        Route::get('/payment-terms-options', [CorporateManagementController::class, 'getPaymentTermsOptions'])->middleware('permission:approvals.view');
        Route::get('/{id}', [CorporateManagementController::class, 'show'])->middleware('permission:approvals.view');
        Route::get('/{id}/credit-terms', [CorporateManagementController::class, 'getCreditTerms'])->middleware('permission:approvals.view');
        Route::post('/{id}/approve', [CorporateManagementController::class, 'approve'])->middleware('permission:approvals.action');
        Route::post('/{id}/reject', [CorporateManagementController::class, 'reject'])->middleware('permission:approvals.action');
        Route::post('/{id}/suspend', [CorporateManagementController::class, 'suspend'])->middleware('permission:approvals.action');
        Route::post('/{id}/reactivate', [CorporateManagementController::class, 'reactivate'])->middleware('permission:approvals.action');
        Route::put('/{id}/credit-terms', [CorporateManagementController::class, 'updateCreditTerms'])->middleware('permission:approvals.action');
    });

    // -----------------------------------------------------------------------
    // Barcode (permission checked via products.view — read-only operation)
    // -----------------------------------------------------------------------
    Route::get('barcode/generate', fn () => response()->json(['message' => 'use get-product-list-for-bar-code']))->middleware('permission:barcode.generate');

    // Activity logs — any authenticated staff can view their own activity; admins can filter by user
    Route::get('activity-logs', [ActivityLogController::class, 'index']);
    Route::get('activity-logs/actions', [ActivityLogController::class, 'actions']);
    Route::get('staff/{user}/activity', [ActivityLogController::class, 'userActivity']);
});

// =========================================================================
// IMS — Admin-only
// =========================================================================

Route::middleware('admin')->group(function () {

    // Product review moderation
    Route::get('reviews/pending', [ProductReviewController::class, 'getPending']);
    Route::post('reviews/{id}/approve', [ProductReviewController::class, 'approve']);
    Route::post('reviews/{id}/reject', [ProductReviewController::class, 'reject']);
    Route::post('reviews/bulk-approve', [ProductReviewController::class, 'bulkApprove']);
    Route::post('reviews/bulk-reject', [ProductReviewController::class, 'bulkReject']);

    // System settings
    Route::get('settings', [SystemSettingController::class, 'index']);
    Route::put('settings', [SystemSettingController::class, 'update']);

    // Banner sliders — full CRUD (public read is GET /hero-banners above)
    Route::get('banners', [BannerSliderController::class, 'adminIndex']);
    Route::post('banners', [BannerSliderController::class, 'store']);
    Route::put('banners/reorder', [BannerSliderController::class, 'reorder']);
    Route::put('banners/{banner}', [BannerSliderController::class, 'update']);
    Route::delete('banners/{banner}', [BannerSliderController::class, 'destroy']);

    // Banner slider global config
    Route::get('banner-config', [BannerConfigController::class, 'show']);
    Route::put('banner-config', [BannerConfigController::class, 'update']);

    // Activity logs — staff list is admin-only; the per-user list page is below in the auth group
    Route::get('activity-logs/staff', [ActivityLogController::class, 'staffList']);

    // Roles & permissions management
    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::get('roles/{role}', [RoleController::class, 'show']);
    Route::put('roles/{role}', [RoleController::class, 'update']);
    Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
    Route::get('permissions', [RoleController::class, 'permissions']);
    Route::get('users/{user}/roles', [RoleController::class, 'userRoles']);
    Route::put('users/{user}/roles', [RoleController::class, 'assignUserRoles']);
});

// =========================================================================
// LEGACY — read-only backward compat, do not add write routes here
// =========================================================================

Route::get('product/menu', [ProductController::class, 'ProductMenu']);
Route::get('get-review/{id}', [ProductReviewController::class, 'show']);
Route::get('products-details-web/{id}', [ProductController::class, 'productsdetails']);
Route::get('orders/{orderId}', [OrderController::class, 'show']);
