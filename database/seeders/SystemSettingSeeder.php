<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $settings = [
            // General
            [
                'key'         => 'site_name',
                'value'       => 'Hometex Bangladesh',
                'type'        => 'string',
                'group'       => 'general',
                'label'       => 'Site Name',
                'description' => 'Displayed in emails and documents.',
            ],
            [
                'key'         => 'site_currency',
                'value'       => 'BDT',
                'type'        => 'string',
                'group'       => 'general',
                'label'       => 'Currency Code',
                'description' => 'ISO 4217 currency code (BDT, USD, etc.).',
            ],
            [
                'key'         => 'site_currency_symbol',
                'value'       => '৳',
                'type'        => 'string',
                'group'       => 'general',
                'label'       => 'Currency Symbol',
                'description' => 'Symbol shown next to prices.',
            ],
            [
                'key'         => 'site_timezone',
                'value'       => 'Asia/Dhaka',
                'type'        => 'string',
                'group'       => 'general',
                'label'       => 'Timezone',
                'description' => 'Used for reports and timestamps.',
            ],

            // Orders
            [
                'key'         => 'order_number_prefix',
                'value'       => 'HT',
                'type'        => 'string',
                'group'       => 'orders',
                'label'       => 'Order Number Prefix',
                'description' => 'Prepended to invoice numbers (e.g. HT-20240001).',
            ],
            [
                'key'         => 'order_vat_percentage',
                'value'       => '0',
                'type'        => 'integer',
                'group'       => 'orders',
                'label'       => 'VAT Percentage',
                'description' => 'Applied to all orders unless overridden.',
            ],
            [
                'key'         => 'order_min_amount',
                'value'       => '0',
                'type'        => 'integer',
                'group'       => 'orders',
                'label'       => 'Minimum Order Amount (BDT)',
                'description' => 'Orders below this amount are rejected.',
            ],

            // Inventory
            [
                'key'         => 'low_stock_threshold',
                'value'       => '5',
                'type'        => 'integer',
                'group'       => 'inventory',
                'label'       => 'Low Stock Threshold',
                'description' => 'Products with stock at or below this quantity are flagged as low stock.',
            ],
            [
                'key'         => 'allow_negative_stock',
                'value'       => '0',
                'type'        => 'boolean',
                'group'       => 'inventory',
                'label'       => 'Allow Negative Stock',
                'description' => 'If enabled, orders can be placed when stock is 0.',
            ],

            // Shipping
            [
                'key'         => 'shipping_charge_inside_dhaka',
                'value'       => '60',
                'type'        => 'integer',
                'group'       => 'shipping',
                'label'       => 'Shipping Charge — Inside Dhaka (BDT)',
                'description' => 'Flat delivery fee for orders within Dhaka.',
            ],
            [
                'key'         => 'shipping_charge_outside_dhaka',
                'value'       => '120',
                'type'        => 'integer',
                'group'       => 'shipping',
                'label'       => 'Shipping Charge — Outside Dhaka (BDT)',
                'description' => 'Flat delivery fee for orders outside Dhaka.',
            ],
            [
                'key'         => 'free_shipping_min_order',
                'value'       => '0',
                'type'        => 'integer',
                'group'       => 'shipping',
                'label'       => 'Free Shipping Minimum Order (BDT)',
                'description' => 'Orders above this amount get free shipping. Set 0 to disable.',
            ],
            [
                'key'         => 'steadfast_enabled',
                'value'       => '1',
                'type'        => 'boolean',
                'group'       => 'shipping',
                'label'       => 'Steadfast Courier Integration',
                'description' => 'Toggle Steadfast order creation on/off without touching code.',
            ],
            [
                'key'         => 'default_delivery_min_days',
                'value'       => '3',
                'type'        => 'integer',
                'group'       => 'shipping',
                'label'       => 'Default Delivery — Min Days',
                'description' => 'Shown on ECOM for products without a per-product delivery window.',
            ],
            [
                'key'         => 'default_delivery_max_days',
                'value'       => '7',
                'type'        => 'integer',
                'group'       => 'shipping',
                'label'       => 'Default Delivery — Max Days',
                'description' => 'Shown on ECOM for products without a per-product delivery window.',
            ],
            [
                'key'         => 'default_return_window_days',
                'value'       => '7',
                'type'        => 'integer',
                'group'       => 'orders',
                'label'       => 'Default Return Window (Days)',
                'description' => 'Fallback return period for products without a per-product return window.',
            ],
            [
                'key'         => 'maintenance_mode_message',
                'value'       => 'We are currently under maintenance. Please check back shortly.',
                'type'        => 'string',
                'group'       => 'general',
                'label'       => 'Maintenance Mode Message',
                'description' => 'Displayed to customers when the site is under maintenance.',
            ],

            // Notifications
            [
                'key'         => 'low_stock_notification_enabled',
                'value'       => '1',
                'type'        => 'boolean',
                'group'       => 'notifications',
                'label'       => 'Low Stock Notifications',
                'description' => 'Send alerts when products hit the low stock threshold.',
            ],
            [
                'key'         => 'low_stock_notification_email',
                'value'       => '',
                'type'        => 'string',
                'group'       => 'notifications',
                'label'       => 'Low Stock Alert Email',
                'description' => 'Email address to notify on low stock.',
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('system_settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, ['created_at' => $now, 'updated_at' => $now])
            );
        }
    }
}
