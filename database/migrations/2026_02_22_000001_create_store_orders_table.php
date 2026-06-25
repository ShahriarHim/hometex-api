<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->string('created_by')->nullable();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('customer_number')->nullable();
            $table->integer('subtotal')->default(0);
            $table->integer('discount_amount')->default(0);
            $table->integer('tax_amount')->default(0);
            $table->integer('total_amount')->default(0);
            $table->integer('paid_amount')->default(0);
            $table->integer('due_amount')->default(0);
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('status')->default('completed');
            $table->text('notes')->nullable();
            $table->string('cancelled_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_orders');
    }
};
