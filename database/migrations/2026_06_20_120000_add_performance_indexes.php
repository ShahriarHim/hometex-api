<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // products — searched by name/sku/status on every list/search request
        Schema::table('products', function (Blueprint $table) {
            $table->index('status');
            $table->index('sku');
            $table->index('category_id');
            $table->index('brand_id');
            $table->index('sub_category_id');
        });

        // products fulltext — LIKE '%search%' without this is O(N) on large catalogs
        DB::statement('ALTER TABLE products ADD FULLTEXT INDEX ft_products_name_sku (name, sku)');

        // shop_product — queried by shop or product on every stock check; foreign keys
        // create individual indexes but not the composite needed for the most common query
        Schema::table('shop_product', function (Blueprint $table) {
            $table->index(['shop_id', 'product_id']);
        });

        // activity_log — filtered by causer + sorted by created_at on every page load
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['causer_type', 'causer_id']);
            $table->index('created_at');
            $table->index('event');
        });

        // orders — filtered/sorted on the orders list page
        Schema::table('orders', function (Blueprint $table) {
            $table->index('order_status');
            $table->index('shop_id');
            $table->index('customer_id');
            $table->index('staff_user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['sku']);
            $table->dropIndex(['category_id']);
            $table->dropIndex(['brand_id']);
            $table->dropIndex(['sub_category_id']);
        });

        DB::statement('ALTER TABLE products DROP INDEX ft_products_name_sku');

        Schema::table('shop_product', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'product_id']);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['causer_type', 'causer_id']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['event']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['order_status']);
            $table->dropIndex(['shop_id']);
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['staff_user_id']);
            $table->dropIndex(['created_at']);
        });
    }
};
