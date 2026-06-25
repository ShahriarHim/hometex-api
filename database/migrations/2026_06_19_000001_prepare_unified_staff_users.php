<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Unifies staff authentication.
 * - Adds employee_type + staff_shop_id to users table
 * - Migrates all sales_managers rows into users as 'staff' user_type
 * - Adds 'staff' to the user_type enum
 *
 * After this migration, SalesManager model and sales_managers table are legacy.
 * The sales_managers table is kept but no new rows should be inserted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Extend user_type enum to include 'staff'
        DB::statement("ALTER TABLE `users` MODIFY `user_type` ENUM('customer','vendor','admin','corporate','staff') NOT NULL DEFAULT 'customer'");

        // 2. Add staff-specific columns
        $columns = Schema::getColumnListing('users');

        Schema::table('users', function (Blueprint $table) use ($columns) {
            if (! in_array('employee_type', $columns)) {
                // 1=manager, 2=product_manager, 3=sales_staff, 4=warehouse
                $table->unsignedTinyInteger('employee_type')->nullable()->after('user_type')
                    ->comment('IMS staff sub-type: 1=manager,2=product_manager,3=sales_staff,4=warehouse');
            }
            if (! in_array('staff_shop_id', $columns)) {
                $table->unsignedBigInteger('staff_shop_id')->nullable()->after('employee_type')
                    ->comment('Primary shop assignment for staff users');
                $table->foreign('staff_shop_id')->references('id')->on('shops')->nullOnDelete();
            }
            if (! in_array('nid', $columns)) {
                $table->string('nid', 30)->nullable()->after('staff_shop_id');
            }
            if (! in_array('nid_photo', $columns)) {
                $table->string('nid_photo')->nullable()->after('nid');
            }
        });

        // 3. Migrate existing sales_managers rows into users
        if (Schema::hasTable('sales_managers')) {
            $managers = DB::table('sales_managers')->get();

            foreach ($managers as $sm) {
                // Check if a user with this email already exists (shouldn't happen, but guard it)
                if ($sm->email && DB::table('users')->where('email', $sm->email)->exists()) {
                    // Just update the user_type and employee_type on the existing user
                    DB::table('users')->where('email', $sm->email)->update([
                        'user_type'     => 'staff',
                        'employee_type' => $sm->employee_type ?? 3,
                        'staff_shop_id' => $sm->shop_id ?? null,
                    ]);
                    continue;
                }

                $nameParts = explode(' ', trim($sm->name ?? ''), 2);

                DB::table('users')->insert([
                    'uuid'          => \Illuminate\Support\Str::uuid(),
                    'first_name'    => $nameParts[0] ?? $sm->name ?? 'Staff',
                    'last_name'     => $nameParts[1] ?? null,
                    'email'         => $sm->email,
                    'phone'         => $sm->phone,
                    'password'      => $sm->password,
                    'avatar'        => $sm->photo,
                    'nid'           => $sm->nid,
                    'nid_photo'     => $sm->nid_photo,
                    'bio'           => $sm->bio,
                    'user_type'     => 'staff',
                    'employee_type' => $sm->employee_type ?? 3,
                    'staff_shop_id' => $sm->shop_id ?? null,
                    'status'        => $sm->status == 1 ? 'active' : 'inactive',
                    'created_at'    => $sm->created_at,
                    'updated_at'    => $sm->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = Schema::getColumnListing('users');
            if (in_array('staff_shop_id', $columns)) {
                $table->dropForeign(['staff_shop_id']);
                $table->dropColumn('staff_shop_id');
            }
            if (in_array('employee_type', $columns)) {
                $table->dropColumn('employee_type');
            }
            if (in_array('nid', $columns)) {
                $table->dropColumn('nid');
            }
            if (in_array('nid_photo', $columns)) {
                $table->dropColumn('nid_photo');
            }
        });

        DB::statement("ALTER TABLE `users` MODIFY `user_type` ENUM('customer','vendor','admin','corporate') NOT NULL DEFAULT 'customer'");
    }
};
