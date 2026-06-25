<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_config', function (Blueprint $table) {
            $table->id();
            $table->boolean('autoplay')->default(true);
            $table->unsignedSmallInteger('autoplay_delay_ms')->default(5000);
            $table->enum('transition', ['fade', 'slide'])->default('fade');
            $table->boolean('show_dots')->default(true);
            $table->boolean('show_arrows')->default(true);
            $table->timestamps();
        });

        // Single-row config — always exists
        DB::table('banner_config')->insert([
            'autoplay'          => true,
            'autoplay_delay_ms' => 5000,
            'transition'        => 'fade',
            'show_dots'         => true,
            'show_arrows'       => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_config');
    }
};
