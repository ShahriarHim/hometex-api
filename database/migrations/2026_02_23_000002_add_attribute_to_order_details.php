<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_details', 'attribute_value_id')) {
            return;
        }
        Schema::table('order_details', function (Blueprint $table) {
            $table->unsignedBigInteger('attribute_value_id')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_details', function (Blueprint $table) {
            $table->dropColumn('attribute_value_id');
        });
    }
};
