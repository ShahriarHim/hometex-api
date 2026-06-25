<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sales_managers');
    }

    public function down(): void
    {
        // Intentionally not recreated — table was from the legacy SalesManager model
        // which was removed during the RBAC overhaul (2026-06-19). All staff are
        // now unified under the users table with Spatie roles.
    }
};
