<?php

namespace App\Http\Controllers;

use App\Manager\OrderManager;
use App\Models\StockLedger;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShopQuantityController extends Controller
{
    /**
     * Reduce shop_quantity for a product in a shop. Records transaction history.
     */
    public function reduce(Request $request): JsonResponse
    {
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        $shopId = (int) $request->shop_id;
        $productId = (int) $request->product_id;
        $quantity = (int) $request->quantity;

        $row = DB::table('shop_product')
            ->where('shop_id', $shopId)
            ->where('product_id', $productId)
            ->first();

        if (!$row) {
            return response()->json([
                'success' => false,
                'message' => 'Product is not assigned to this shop.',
            ], 404);
        }

        $current = (int) $row->quantity;
        if ($current < $quantity) {
            return response()->json([
                'success' => false,
                'message' => "Insufficient shop quantity. Available: {$current}, requested: {$quantity}.",
            ], 400);
        }

        try {
            DB::beginTransaction();

            OrderManager::decrementShopProductStock($shopId, $productId, $quantity);

            StockLedger::create([
                'shop_id' => $shopId,
                'product_id' => $productId,
                'quantity_change' => -$quantity,
                'type' => StockLedger::TYPE_MANUAL,
                'reference_type' => null,
                'reference_id' => null,
                'created_by' => $request->user()?->id,
                'notes' => $request->input('notes'),
            ]);

            $updated = DB::table('shop_product')
                ->where('shop_id', $shopId)
                ->where('product_id', $productId)
                ->first();

            DB::commit();

            CacheService::clearProductCaches();

            return response()->json([
                'success' => true,
                'message' => 'Shop quantity reduced.',
                'data' => [
                    'id' => $updated->id,
                    'product_id' => $updated->product_id,
                    'shop_id' => $updated->shop_id,
                    'quantity' => (int) $updated->quantity,
                    'updated_at' => $updated->updated_at,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reduce shop quantity: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Shops that have stock for a product (quantity > 0).
     * Query: product_id (required).
     * Returns [{ shop_id, shop_name, quantity }, ...].
     */
    public function productShops(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);
        $productId = (int) $request->product_id;
        $rows = DB::table('shop_product')
            ->join('shops', 'shops.id', '=', 'shop_product.shop_id')
            ->where('shop_product.product_id', $productId)
            ->where('shop_product.quantity', '>', 0)
            ->select('shop_product.shop_id', 'shops.name as shop_name', 'shop_product.quantity')
            ->orderBy('shop_product.quantity', 'desc')
            ->get();
        $out = $rows->map(fn ($r) => [
            'shop_id' => (int) $r->shop_id,
            'shop_name' => $r->shop_name,
            'quantity' => (int) $r->quantity,
        ])->values()->all();
        return response()->json($out);
    }

    /**
     * Get current stock (quantity) for given products in a shop.
     * Query: shop_id (required), product_ids comma-separated (e.g. 435,436).
     * Returns { "435": 10, "436": 5 } (product_id => quantity).
     */
    public function stock(Request $request): JsonResponse
    {
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'product_ids' => 'required|string',
        ]);
        $shopId = (int) $request->shop_id;
        $ids = array_filter(array_map('intval', explode(',', $request->product_ids)));
        if (empty($ids)) {
            return response()->json([]);
        }
        $rows = DB::table('shop_product')
            ->where('shop_id', $shopId)
            ->whereIn('product_id', $ids)
            ->pluck('quantity', 'product_id');
        $out = [];
        foreach ($rows as $productId => $quantity) {
            $out[(string) $productId] = (int) $quantity;
        }
        return response()->json($out);
    }

    /**
     * List shop quantity transaction history.
     */
    public function transactions(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 1), 100);

        $query = StockLedger::query()
            ->with(['shop:id,name', 'product:id,name,sku', 'createdByUser:id,first_name,last_name']);

        if ($request->filled('shop_id')) {
            $query->where('shop_id', $request->shop_id);
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $query->orderByDesc('created_at');
        $items = $query->paginate($perPage);

        return response()->json($items);
    }
}
