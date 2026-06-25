-- Run this as your DB user.
-- From server: cd /var/www/hometex-api && mysql -u $(grep DB_USERNAME .env | cut -d= -f2) -p$(grep DB_PASSWORD .env | cut -d= -f2) $(grep DB_DATABASE .env | cut -d= -f2) < database/migrations/MANUAL_RUN_PENDING.sql
-- Or interactively: mysql -u YOUR_DB_USER -p YOUR_DB_NAME
-- Then: source /var/www/hometex-api/database/migrations/MANUAL_RUN_PENDING.sql;
--
-- If payment_methods table does not exist, remove the payment_method_id FK from store_orders before running.

-- 1. users: add 'pending' and 'rejected' to status enum
ALTER TABLE `users` MODIFY COLUMN `status` ENUM('active', 'inactive', 'suspended', 'pending_verification', 'pending', 'rejected') NOT NULL DEFAULT 'active';

-- 2. product_wise_faqs table
CREATE TABLE IF NOT EXISTS `product_wise_faqs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint unsigned NOT NULL,
  `question` text NOT NULL,
  `answer` text NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_wise_faqs_product_id_is_active_index` (`product_id`,`is_active`),
  KEY `product_wise_faqs_sort_order_index` (`sort_order`),
  CONSTRAINT `product_wise_faqs_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. store_orders table
CREATE TABLE IF NOT EXISTS `store_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created_by` varchar(255) DEFAULT NULL,
  `shop_id` bigint unsigned NOT NULL,
  `customer_number` varchar(255) DEFAULT NULL,
  `subtotal` int NOT NULL DEFAULT 0,
  `discount_amount` int NOT NULL DEFAULT 0,
  `tax_amount` int NOT NULL DEFAULT 0,
  `total_amount` int NOT NULL DEFAULT 0,
  `paid_amount` int NOT NULL DEFAULT 0,
  `due_amount` int NOT NULL DEFAULT 0,
  `payment_method_id` bigint unsigned DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'completed',
  `notes` text,
  `cancelled_by` varchar(255) DEFAULT NULL,
  `reason` text,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `store_orders_shop_id_foreign` (`shop_id`),
  KEY `store_orders_payment_method_id_foreign` (`payment_method_id`),
  CONSTRAINT `store_orders_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `store_orders_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. store_order_details table (after store_orders)
CREATE TABLE IF NOT EXISTS `store_order_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_order_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `quantity` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `store_order_details_store_order_id_foreign` (`store_order_id`),
  KEY `store_order_details_product_id_foreign` (`product_id`),
  CONSTRAINT `store_order_details_store_order_id_foreign` FOREIGN KEY (`store_order_id`) REFERENCES `store_orders` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `store_order_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. shop_product_transactions table
CREATE TABLE IF NOT EXISTS `shop_product_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` bigint unsigned NOT NULL,
  `product_id` bigint unsigned NOT NULL,
  `quantity_change` int NOT NULL COMMENT 'Negative = reduction, positive = increase',
  `type` varchar(50) NOT NULL COMMENT 'ecommerce_order, store_order, manual, restore, transfer_in, transfer_out',
  `reference_type` varchar(100) DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shop_product_transactions_shop_id_product_id_index` (`shop_id`,`product_id`),
  KEY `shop_product_transactions_reference_index` (`reference_type`,`reference_id`),
  KEY `shop_product_transactions_created_at_index` (`created_at`),
  CONSTRAINT `shop_product_transactions_shop_id_foreign` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shop_product_transactions_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shop_product_transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. orders: add stock_adjusted_at
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'stock_adjusted_at');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `orders` ADD COLUMN `stock_adjusted_at` TIMESTAMP NULL AFTER `updated_at`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 7. order_details: add product_id
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_details' AND COLUMN_NAME = 'product_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `order_details` ADD COLUMN `product_id` BIGINT UNSIGNED NULL AFTER `order_id`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 8. order_history table
CREATE TABLE IF NOT EXISTS `order_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `description` text,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_history_order_id_created_at_index` (`order_id`,`created_at`),
  CONSTRAINT `order_history_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. order_details: add attribute_value_id
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_details' AND COLUMN_NAME = 'attribute_value_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE `order_details` ADD COLUMN `attribute_value_id` BIGINT UNSIGNED NULL AFTER `product_id`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Mark migrations as run (fix migration status). Run only after the DDL above succeeded.
SET @batch = (SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations m);
INSERT INTO migrations (migration, batch)
SELECT v.migration, @batch FROM (
  SELECT '2025_12_27_210500_add_pending_rejected_to_users_status' AS migration
  UNION SELECT '2026_01_09_000002_create_product_wise_faqs_table'
  UNION SELECT '2026_02_22_000001_create_store_orders_table'
  UNION SELECT '2026_02_22_000002_create_store_order_details_table'
  UNION SELECT '2026_02_22_100000_create_shop_product_transactions_table'
  UNION SELECT '2026_02_22_120000_add_stock_adjusted_at_to_orders_table'
  UNION SELECT '2026_02_23_000001_add_order_edit_support'
  UNION SELECT '2026_02_23_000002_add_attribute_to_order_details'
) v
WHERE NOT EXISTS (SELECT 1 FROM migrations m WHERE m.migration = v.migration);
