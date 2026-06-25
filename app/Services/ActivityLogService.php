<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit trail — wraps spatie/laravel-activitylog.
 *
 * Always call AFTER DB::commit(). Never call inside a transaction.
 * Fire-and-forget: all exceptions are swallowed so a log failure
 * never propagates to the calling request.
 *
 * Separate from user_activity_logs (security/session log — login,
 * logout, IP, user agent). This tracks business actions: who did what
 * to which record, with before/after property diffs where applicable.
 */
class ActivityLogService
{
    /**
     * Write a named audit log entry, optionally linked to a subject model.
     * Safe to call anywhere outside a transaction — never throws.
     */
    public static function log(
        string  $action,
        string  $description,
        array   $metadata   = [],
        ?Model  $subject    = null,
        ?string $logName    = 'audit',
    ): void {
        try {
            $logger = activity($logName)
                ->withProperties($metadata)
                ->event($action);

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            $logger->log($description);
        } catch (\Throwable) {
            // Logging must never break the application
        }
    }

    // ------------------------------------------------------------------
    // Typed helpers — keep action strings consistent across the codebase
    // ------------------------------------------------------------------

    public static function orderCreated(int $orderId, string $invoiceId): void
    {
        static::log('order.created', "Order #{$invoiceId} created", ['order_id' => $orderId]);
    }

    public static function orderCancelled(int $orderId, string $invoiceId): void
    {
        static::log('order.cancelled', "Order #{$invoiceId} cancelled", ['order_id' => $orderId]);
    }

    public static function orderUpdated(int $orderId, string $invoiceId): void
    {
        static::log('order.updated', "Order #{$invoiceId} updated", ['order_id' => $orderId]);
    }

    public static function stockAdjusted(int $productId, int $shopId, int $quantity, string $reason): void
    {
        static::log('inventory.adjusted', "Stock adjusted for product #{$productId}", [
            'product_id' => $productId,
            'shop_id'    => $shopId,
            'quantity'   => $quantity,
            'reason'     => $reason,
        ]);
    }

    public static function transferCreated(int $transferId): void
    {
        static::log('inventory.transfer.created', "Transfer #{$transferId} created", ['transfer_id' => $transferId]);
    }

    public static function transferApproved(int $transferId): void
    {
        static::log('inventory.transfer.approved', "Transfer #{$transferId} approved", ['transfer_id' => $transferId]);
    }

    public static function transferRejected(int $transferId): void
    {
        static::log('inventory.transfer.rejected', "Transfer #{$transferId} rejected", ['transfer_id' => $transferId]);
    }

    public static function staffCreated(int $staffId, string $name): void
    {
        static::log('staff.created', "Staff member '{$name}' created", ['staff_id' => $staffId]);
    }

    public static function staffUpdated(int $staffId, string $name): void
    {
        static::log('staff.updated', "Staff member '{$name}' updated", ['staff_id' => $staffId]);
    }

    public static function staffDeleted(int $staffId, string $name): void
    {
        static::log('staff.deleted', "Staff member '{$name}' deleted", ['staff_id' => $staffId]);
    }

    public static function corporateApproved(int $userId): void
    {
        static::log('corporate.approved', "Corporate account #{$userId} approved", ['user_id' => $userId]);
    }

    public static function corporateRejected(int $userId): void
    {
        static::log('corporate.rejected', "Corporate account #{$userId} rejected", ['user_id' => $userId]);
    }

    public static function rolePermissionsUpdated(string $roleName): void
    {
        static::log('role.permissions_updated', "Permissions updated for role '{$roleName}'", ['role' => $roleName]);
    }

    public static function productCreated(int $productId, string $productName): void
    {
        static::log('product.created', "Created product: {$productName}", ['product_id' => $productId, 'product_name' => $productName]);
    }

    public static function productUpdated(int $productId, string $productName, array $updatedFields = []): void
    {
        static::log('product.updated', "Updated product: {$productName}", ['product_id' => $productId, 'product_name' => $productName, 'updated_fields' => $updatedFields]);
    }

    public static function brandCreated(int $brandId, string $name): void
    {
        static::log('brand.created', "Created brand: {$name}", ['brand_id' => $brandId]);
    }

    public static function brandUpdated(int $brandId, string $name): void
    {
        static::log('brand.updated', "Updated brand: {$name}", ['brand_id' => $brandId]);
    }

    public static function brandDeleted(int $brandId, string $name): void
    {
        static::log('brand.deleted', "Deleted brand: {$name}", ['brand_id' => $brandId]);
    }

    public static function categoryCreated(int $categoryId, string $name, string $type = 'category'): void
    {
        static::log("{$type}.created", "Created {$type}: {$name}", ["{$type}_id" => $categoryId]);
    }

    public static function categoryUpdated(int $categoryId, string $name, string $type = 'category'): void
    {
        static::log("{$type}.updated", "Updated {$type}: {$name}", ["{$type}_id" => $categoryId]);
    }

    public static function categoryDeleted(int $categoryId, string $name, string $type = 'category'): void
    {
        static::log("{$type}.deleted", "Deleted {$type}: {$name}", ["{$type}_id" => $categoryId]);
    }

    public static function supplierCreated(int $supplierId, string $name): void
    {
        static::log('supplier.created', "Created supplier: {$name}", ['supplier_id' => $supplierId]);
    }

    public static function supplierUpdated(int $supplierId, string $name): void
    {
        static::log('supplier.updated', "Updated supplier: {$name}", ['supplier_id' => $supplierId]);
    }

    public static function supplierDeleted(int $supplierId, string $name): void
    {
        static::log('supplier.deleted', "Deleted supplier: {$name}", ['supplier_id' => $supplierId]);
    }

    public static function shopCreated(int $shopId, string $name): void
    {
        static::log('shop.created', "Created shop: {$name}", ['shop_id' => $shopId]);
    }

    public static function shopUpdated(int $shopId, string $name): void
    {
        static::log('shop.updated', "Updated shop: {$name}", ['shop_id' => $shopId]);
    }

    public static function shopDeleted(int $shopId, string $name): void
    {
        static::log('shop.deleted', "Deleted shop: {$name}", ['shop_id' => $shopId]);
    }
}
