<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // orders: rename sales_manager_id -> staff_user_id, repoint FK to users
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_sales_manager_id_foreign');
            $table->renameColumn('sales_manager_id', 'staff_user_id');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('staff_user_id')->nullable()->change();
            $table->foreign('staff_user_id')->references('id')->on('users')->nullOnDelete()->cascadeOnUpdate();
        });

        // Drop legacy sales_managers table (confirmed empty, all staff now in users)
        Schema::dropIfExists('sales_managers');

        // store_orders: add user ID FK columns alongside legacy varchar columns
        Schema::table('store_orders', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_by')->constrained('users')->nullOnDelete()->cascadeOnUpdate();
        });

        // store_orders.status: varchar -> enum
        DB::statement("ALTER TABLE store_orders MODIFY COLUMN status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'completed'");

        // Typo fixes
        Schema::table('product_offer_requests', function (Blueprint $table) {
            $table->renameColumn('quentity', 'quantity');
        });
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->renameColumn('attribute_mesarment', 'attribute_measurement');
        });

        // Add missing unique constraint on inventory pivot
        Schema::table('shop_product', function (Blueprint $table) {
            $table->unique(['shop_id', 'product_id'], 'shop_product_unique');
        });

        // Drop migration accident table
        Schema::dropIfExists('product_seo_meta_data_table_if_not_exists');

        // R2 image migration: add logo_full / photo_full columns for dual-key storage
        Schema::table('brands', function (Blueprint $table) {
            $table->string('logo_full')->nullable()->after('logo');
        });
        Schema::table('shops', function (Blueprint $table) {
            $table->string('logo_full')->nullable()->after('logo');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('logo_full')->nullable()->after('logo');
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->string('photo_full')->nullable()->after('photo');
        });
        Schema::table('product_photos', function (Blueprint $table) {
            $table->string('photo_full')->nullable()->after('photo');
        });
    }

    public function down(): void
    {
        // Restore unique constraint
        Schema::table('shop_product', function (Blueprint $table) {
            $table->dropUnique('shop_product_unique');
        });

        // Restore typos
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->renameColumn('attribute_measurement', 'attribute_mesarment');
        });
        Schema::table('product_offer_requests', function (Blueprint $table) {
            $table->renameColumn('quantity', 'quentity');
        });

        // Restore store_orders status
        DB::statement("ALTER TABLE store_orders MODIFY COLUMN status VARCHAR(255) NOT NULL DEFAULT 'completed'");

        // Remove store_orders FK columns
        Schema::table('store_orders', function (Blueprint $table) {
            $table->dropForeign('store_orders_cancelled_by_user_id_foreign');
            $table->dropForeign('store_orders_created_by_user_id_foreign');
            $table->dropColumn(['created_by_user_id', 'cancelled_by_user_id']);
        });

        // Restore orders FK (can't restore sales_managers table data — down() is best-effort)
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_staff_user_id_foreign');
            $table->renameColumn('staff_user_id', 'sales_manager_id');
        });

        // Drop R2 dual-key columns
        Schema::table('product_photos', fn (Blueprint $t) => $t->dropColumn('photo_full'));
        Schema::table('categories',     fn (Blueprint $t) => $t->dropColumn('photo_full'));
        Schema::table('suppliers',      fn (Blueprint $t) => $t->dropColumn('logo_full'));
        Schema::table('shops',          fn (Blueprint $t) => $t->dropColumn('logo_full'));
        Schema::table('brands',         fn (Blueprint $t) => $t->dropColumn('logo_full'));
    }
};
