<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['name', 'user_id']);
        });
    }
};
