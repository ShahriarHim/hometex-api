<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds all Spatie roles and permissions.
 *
 * IMS Roles:  admin, manager, product_manager, sales_staff, warehouse
 * ECOM Roles: customer, corporate
 *
 * Run:  php artisan db:seed --class=RolePermissionSeeder
 * Re-runnable: uses firstOrCreate throughout, safe to run multiple times.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Permissions grouped by module.
     * Format: 'module.action'
     */
    private array $permissions = [
        // Dashboard
        'dashboard.view',
        'dashboard.export',

        // Products
        'products.view',
        'products.create',
        'products.edit',
        'products.delete',
        'products.import',
        'products.export',

        // Catalog (brands, categories)
        'catalog.view',
        'catalog.create',
        'catalog.edit',
        'catalog.delete',

        // Attributes & Pricing
        'attributes.view',
        'attributes.manage',
        'pricing.view',
        'pricing.manage',

        // Inventory
        'inventory.view',
        'inventory.transfer.create',
        'inventory.transfer.approve',
        'inventory.adjust',

        // Orders (IMS / POS)
        'orders.view',
        'orders.create',
        'orders.edit',
        'orders.cancel',
        'orders.export',

        // Store Orders
        'store_orders.view',
        'store_orders.cancel',

        // Customers
        'customers.view',
        'customers.create',
        'customers.edit',
        'customers.delete',

        // Returns
        'returns.view',
        'returns.process',

        // Suppliers
        'suppliers.view',
        'suppliers.create',
        'suppliers.edit',
        'suppliers.delete',

        // Shops / Branches
        'shops.view',
        'shops.create',
        'shops.edit',
        'shops.delete',

        // Staff / Employees
        'staff.view',
        'staff.create',
        'staff.edit',
        'staff.delete',

        // Approvals (corporate accounts)
        'approvals.view',
        'approvals.action',

        // Reports
        'reports.view',
        'reports.export',

        // Barcode
        'barcode.generate',

        // Banners (e-commerce slides)
        'banners.view',
        'banners.manage',

        // Analytics (product rankings, per-product analytics)
        'analytics.view',

        // Roles & Permissions (admin only)
        'roles.view',
        'roles.manage',
    ];

    /**
     * Role → permission map.
     * Null means the role gets ALL permissions.
     */
    private array $rolePermissions = [
        // IMS roles
        'admin' => null, // all permissions

        'manager' => [
            'dashboard.view', 'dashboard.export',
            'products.view', 'products.create', 'products.edit',
            'catalog.view', 'catalog.create', 'catalog.edit',
            'attributes.view', 'attributes.manage',
            'pricing.view', 'pricing.manage',
            'inventory.view', 'inventory.transfer.create', 'inventory.transfer.approve', 'inventory.adjust',
            'orders.view', 'orders.create', 'orders.edit', 'orders.cancel', 'orders.export',
            'store_orders.view', 'store_orders.cancel',
            'customers.view', 'customers.create', 'customers.edit',
            'returns.view', 'returns.process',
            'suppliers.view', 'suppliers.create', 'suppliers.edit',
            'shops.view',
            'staff.view',
            'approvals.view', 'approvals.action',
            'reports.view', 'reports.export',
            'analytics.view',
            'banners.view',
            'barcode.generate',
        ],

        'product_manager' => [
            'dashboard.view',
            'products.view', 'products.create', 'products.edit', 'products.import', 'products.export',
            'catalog.view', 'catalog.create', 'catalog.edit',
            'attributes.view', 'attributes.manage',
            'pricing.view', 'pricing.manage',
            'inventory.view', 'inventory.transfer.create',
            'orders.view',
            'suppliers.view',
            'barcode.generate',
            'reports.view',
            'analytics.view',
        ],

        'sales_staff' => [
            'dashboard.view',
            'products.view',
            'orders.view', 'orders.create', 'orders.edit', 'orders.cancel',
            'store_orders.view',
            'customers.view', 'customers.create',
            'returns.view', 'returns.process',
            'inventory.view',
            'barcode.generate',
        ],

        'warehouse' => [
            'dashboard.view',
            'products.view',
            'inventory.view', 'inventory.transfer.create', 'inventory.adjust',
            'orders.view',
            'barcode.generate',
            'reports.view',
        ],

        // ECOM roles — no IMS permissions, just role markers
        'customer'  => [],
        'corporate' => [],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create all permissions
        foreach ($this->permissions as $permName) {
            Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'sanctum']);
        }

        // Create roles and assign permissions
        foreach ($this->rolePermissions as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'sanctum']);

            if ($perms === null) {
                // Admin gets everything
                $role->syncPermissions(Permission::all());
            } elseif (! empty($perms)) {
                $role->syncPermissions($perms);
            }
            // empty array = role exists but has no permissions (customer/corporate)
        }

        $this->command->info('Roles and permissions seeded successfully.');
        $this->command->table(
            ['Role', 'Permissions'],
            collect($this->rolePermissions)->map(fn ($perms, $role) => [
                $role,
                $perms === null ? 'ALL (' . count($this->permissions) . ')' : count((array) $perms),
            ])->toArray()
        );
    }
}
