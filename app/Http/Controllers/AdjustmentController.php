<?php

namespace App\Http\Controllers;

use App\Models\ShopProduct;
use App\Models\StockLedger;
use App\Services\ActivityLogService;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdjustmentController extends Controller
{
    /**
     * POST /api/adjustments
     *
     * Body: product_id, shop_id, type (add|subtract|set), quantity, attribute_id?, notes?
     *
     * - add:      increments shop stock by quantity
     * - subtract: decrements shop stock by quantity (errors if insufficient)
     * - set:      sets stock to exact quantity value
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'product_id'   => 'required|exists:products,id',
            'shop_id'      => 'required|exists:shops,id',
            'type'         => 'required|in:add,subtract,set',
            'quantity'     => 'required|integer|min:1',
            'attribute_id' => 'nullable|integer',
            'notes'        => 'nullable|string|max:500',
        ]);

        $productId = (int) $request->product_id;
        $shopId    = (int) $request->shop_id;
        $type      = $request->type;
        $quantity  = (int) $request->quantity;
        $notes     = $request->input('notes');
        $userId    = $request->user()?->id;

        $assignedShopId = $request->user('sanctum')?->assignedShopId();
        if ($assignedShopId && $shopId !== $assignedShopId) {
            return response()->json(['status' => 'error', 'message' => 'You can only adjust stock for your assigned branch.'], 403);
        }

        $pivot = ShopProduct::where('shop_id', $shopId)
            ->where('product_id', $productId)
            ->first();

        if ($type === 'subtract') {
            $current = $pivot ? (int) $pivot->quantity : 0;
            if ($current < $quantity) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Insufficient stock. Available: {$current}, requested: {$quantity}.",
                ], 400);
            }
        }

        try {
            DB::beginTransaction();

            if (!$pivot) {
                if ($type === 'subtract') {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => 'Product is not assigned to this shop.',
                    ], 404);
                }
                $pivot = ShopProduct::create([
                    'shop_id'    => $shopId,
                    'product_id' => $productId,
                    'quantity'   => 0,
                ]);
            }

            $before = (int) $pivot->quantity;

            if ($type === 'add') {
                $pivot->increment('quantity', $quantity);
                $change = $quantity;
            } elseif ($type === 'subtract') {
                $pivot->decrement('quantity', $quantity);
                $change = -$quantity;
            } else {
                $change = $quantity - $before;
                $pivot->update(['quantity' => $quantity]);
            }

            StockLedger::create([
                'shop_id'        => $shopId,
                'product_id'     => $productId,
                'quantity_change' => $change,
                'type'           => StockLedger::TYPE_MANUAL,
                'reference_type' => null,
                'reference_id'   => null,
                'created_by'     => $userId,
                'notes'          => $notes,
            ]);

            DB::commit();
            CacheService::clearProductCaches();
            ActivityLogService::stockAdjusted($productId, $shopId, $change, $notes ?? $type);

            return response()->json([
                'status'  => 'success',
                'message' => 'Stock adjusted successfully.',
                'data'    => [
                    'product_id' => $productId,
                    'shop_id'    => $shopId,
                    'before'     => $before,
                    'after'      => (int) $pivot->fresh()->quantity,
                    'change'     => $change,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Adjustment failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
