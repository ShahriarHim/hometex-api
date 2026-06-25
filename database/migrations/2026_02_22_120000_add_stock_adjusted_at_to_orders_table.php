<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'stock_adjusted_at')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_adjusted_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stock_adjusted_at');
        });
    }
};
