<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banner_sliders', function (Blueprint $table) {
            // Layout preset — determines which component ECOM renders
            $table->string('preset', 50)->default('striped_overlay')->after('name');

            // Content fields
            $table->string('heading', 255)->nullable()->after('preset');
            $table->string('subheading', 255)->nullable()->after('heading');
            $table->string('button_label', 100)->nullable()->after('subheading');
            $table->string('button_url', 500)->nullable()->after('button_label');

            // Style fields
            $table->string('bg_color', 20)->nullable()->after('button_url');
            $table->string('stripe_color', 20)->nullable()->after('bg_color');
            $table->string('text_color', 20)->nullable()->after('stripe_color');
            $table->string('button_color', 20)->nullable()->after('text_color');
            $table->enum('text_position', ['left', 'center', 'right'])->default('center')->after('button_color');
            $table->boolean('animate_stripes')->default(true)->after('text_position');

            // Images — slider is existing bg image key; overlay_images are floating animated images
            $table->json('overlay_images')->nullable()->after('animate_stripes');

            // Rename sl → order_position for clarity (keep sl for backward compat)
            // We add order_position as new column; sl stays for existing data
            $table->unsignedInteger('order_position')->default(0)->after('overlay_images');

            $table->index('order_position');
        });
    }

    public function down(): void
    {
        Schema::table('banner_sliders', function (Blueprint $table) {
            $table->dropColumn([
                'preset', 'heading', 'subheading', 'button_label', 'button_url',
                'bg_color', 'stripe_color', 'text_color', 'button_color',
                'text_position', 'animate_stripes', 'overlay_images', 'order_position',
            ]);
        });
    }
};
