<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            // Sale price at time of transaction — NULL for non-sale rows (transfers, adjustments, returns)
            $table->decimal('unit_price', 10, 2)->nullable()->after('quantity_change');
        });
    }

    public function down(): void
    {
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropColumn('unit_price');
        });
    }
};
