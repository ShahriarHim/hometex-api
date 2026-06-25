<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('shop_product_transactions', 'stock_ledger');
    }

    public function down(): void
    {
        Schema::rename('stock_ledger', 'shop_product_transactions');
    }
};
