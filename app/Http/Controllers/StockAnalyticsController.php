<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockAnalyticsController extends Controller
{
    private const SALE_TYPES = ['ecommerce_order', 'pos_order', 'store_order'];

    /**
     * GET /api/analytics/products
     *
     * Returns top-selling products ranked by units sold over a given period.
     * Used for "Hot Sale", "Trending", "Discount Candidate" badges in dashboard.
     *
     * Query params:
     *   days     (int, default 30)   — lookback window
     *   limit    (int, default 20)   — number of products to return
     *   type     (string, optional)  — filter by sale type: ecommerce_order|pos_order|store_order
     *   shop_id  (int, optional)     — filter by specific shop
     */
    public function productRankings(Request $request): JsonResponse
    {
        $days   = max(1, (int) ($request->input('days', 30)));
        $limit  = min(100, max(1, (int) ($request->input('limit', 20))));
        $type   = $request->input('type');

        // Branch-scoped: staff with an assigned shop can only see analytics for that shop
        $assignedShopId = $request->user('sanctum')?->assignedShopId();
        $shopId = $assignedShopId ?? ($request->input('shop_id') ?: null);
        if ($type !== null && !in_array($type, self::SALE_TYPES, true)) {
            $type = null;
        }

        $since = now()->subDays($days);

        $query = DB::table('stock_ledger as sl')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->select(
                'p.id',
                'p.name',
                'p.sku',
                'p.price',
                'p.stock',
                'p.discount_percent',
                DB::raw('SUM(ABS(sl.quantity_change)) as units_sold'),
                DB::raw('SUM(ABS(sl.quantity_change) * COALESCE(sl.unit_price, p.price)) as revenue'),
                DB::raw('COUNT(DISTINCT DATE(sl.created_at)) as active_days'),
                DB::raw('SUM(CASE WHEN sl.type = \'ecommerce_order\' THEN ABS(sl.quantity_change) ELSE 0 END) as ecom_units'),
                DB::raw('SUM(CASE WHEN sl.type = \'pos_order\' THEN ABS(sl.quantity_change) ELSE 0 END) as pos_units'),
                DB::raw('SUM(CASE WHEN sl.type = \'store_order\' THEN ABS(sl.quantity_change) ELSE 0 END) as store_units')
            )
            ->where('sl.created_at', '>=', $since)
            ->whereIn('sl.type', $type ? [$type] : self::SALE_TYPES)
            ->groupBy('p.id', 'p.name', 'p.sku', 'p.price', 'p.stock', 'p.discount_percent')
            ->orderByDesc('units_sold')
            ->limit($limit);

        if ($shopId) {
            $query->where('sl.shop_id', (int) $shopId);
        }

        $rows = $query->get();

        // Compute max units_sold to normalise a "heat" score 0–100
        $maxSold = $rows->max('units_sold') ?: 1;

        $data = $rows->map(function ($r) use ($maxSold) {
            $heat = (int) round(($r->units_sold / $maxSold) * 100);
            return [
                'id'               => $r->id,
                'name'             => $r->name,
                'sku'              => $r->sku,
                'price'            => (int) $r->price,
                'stock'            => (int) $r->stock,
                'discount_percent' => (int) $r->discount_percent,
                'units_sold'       => (int) $r->units_sold,
                'revenue'          => (int) $r->revenue,
                'active_days'      => (int) $r->active_days,
                'ecom_units'       => (int) $r->ecom_units,
                'pos_units'        => (int) $r->pos_units,
                'store_units'      => (int) $r->store_units,
                'heat'             => $heat,
                'badge'            => $this->badge($heat, (int) $r->stock, (int) $r->discount_percent),
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'days'  => $days,
                'limit' => $limit,
                'total' => $data->count(),
            ],
        ]);
    }

    /**
     * GET /api/analytics/products/{id}
     *
     * Per-product deep analytics:
     *   - Day-by-day sales trend
     *   - Channel breakdown (ecom vs pos vs store)
     *   - Per-shop breakdown
     *   - All movement types (transfers, returns, adjustments)
     *   - Current stock per shop
     *
     * Query params:
     *   days (int, default 30)
     */
    public function productDetail(Request $request, int $id): JsonResponse
    {
        $days           = max(1, (int) ($request->input('days', 30)));
        $since          = now()->subDays($days);
        $assignedShopId = $request->user('sanctum')?->assignedShopId();

        $user           = $request->user('sanctum');
        $showCost       = $user && ($user->hasRole('admin') || $user->hasRole('manager'));
        $productFields  = ['id', 'name', 'sku', 'price', 'stock', 'discount_percent', 'status'];
        if ($showCost) {
            $productFields[] = 'cost';
        }
        $product = Product::select($productFields)->findOrFail($id);

        // Day-by-day sales trend (sales movements only)
        $dailyTrend = DB::table('stock_ledger')
            ->select(DB::raw('DATE(created_at) as day'), DB::raw('SUM(ABS(quantity_change)) as units'))
            ->where('product_id', $id)
            ->whereIn('type', self::SALE_TYPES)
            ->where('created_at', '>=', $since)
            ->when($assignedShopId, fn ($q) => $q->where('shop_id', $assignedShopId))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => ['day' => $r->day, 'units' => (int) $r->units]);

        // Channel breakdown
        $channelBreakdown = DB::table('stock_ledger')
            ->select('type', DB::raw('SUM(ABS(quantity_change)) as units'), DB::raw('COUNT(*) as orders'))
            ->where('product_id', $id)
            ->where('created_at', '>=', $since)
            ->whereIn('type', self::SALE_TYPES)
            ->when($assignedShopId, fn ($q) => $q->where('shop_id', $assignedShopId))
            ->groupBy('type')
            ->get()
            ->map(fn ($r) => ['type' => $r->type, 'units' => (int) $r->units, 'orders' => (int) $r->orders]);

        // Per-shop breakdown
        $shopBreakdown = DB::table('stock_ledger as sl')
            ->join('shops', 'shops.id', '=', 'sl.shop_id')
            ->select('shops.id as shop_id', 'shops.name as shop_name',
                DB::raw('SUM(ABS(sl.quantity_change)) as units_sold'))
            ->where('sl.product_id', $id)
            ->where('sl.created_at', '>=', $since)
            ->whereIn('sl.type', self::SALE_TYPES)
            ->when($assignedShopId, fn ($q) => $q->where('sl.shop_id', $assignedShopId))
            ->groupBy('shops.id', 'shops.name')
            ->orderByDesc('units_sold')
            ->get()
            ->map(fn ($r) => ['shop_id' => $r->shop_id, 'shop_name' => $r->shop_name, 'units_sold' => (int) $r->units_sold]);

        // Full movement log (all types)
        $movements = DB::table('stock_ledger as sl')
            ->join('shops', 'shops.id', '=', 'sl.shop_id')
            ->leftJoin('users', 'users.id', '=', 'sl.created_by')
            ->select(
                'sl.id', 'sl.type', 'sl.quantity_change',
                'sl.reference_type', 'sl.reference_id', 'sl.notes',
                'sl.created_at',
                'shops.name as shop_name',
                DB::raw('CONCAT(COALESCE(users.first_name,\'\'),\' \',COALESCE(users.last_name,\'\')) as created_by_name')
            )
            ->where('sl.product_id', $id)
            ->where('sl.created_at', '>=', $since)
            ->when($assignedShopId, fn ($q) => $q->where('sl.shop_id', $assignedShopId))
            ->orderByDesc('sl.created_at')
            ->limit(200)
            ->get()
            ->map(fn ($r) => [
                'id'              => $r->id,
                'type'            => $r->type,
                'quantity_change' => (int) $r->quantity_change,
                'shop'            => $r->shop_name,
                'reference'       => $r->reference_type ? (class_basename($r->reference_type) . ' #' . $r->reference_id) : null,
                'notes'           => $r->notes,
                'created_by'      => trim($r->created_by_name) ?: null,
                'created_at'      => $r->created_at,
            ]);

        // Current stock per shop
        $shopStock = DB::table('shop_product')
            ->join('shops', 'shops.id', '=', 'shop_product.shop_id')
            ->where('shop_product.product_id', $id)
            ->when($assignedShopId, fn ($q) => $q->where('shop_product.shop_id', $assignedShopId))
            ->select('shops.id as shop_id', 'shops.name as shop_name', 'shop_product.quantity')
            ->orderByDesc('shop_product.quantity')
            ->get()
            ->map(fn ($r) => ['shop_id' => $r->shop_id, 'shop_name' => $r->shop_name, 'quantity' => (int) $r->quantity]);

        // Summary totals
        $totals = DB::table('stock_ledger')
            ->where('product_id', $id)
            ->where('created_at', '>=', $since)
            ->when($assignedShopId, fn ($q) => $q->where('shop_id', $assignedShopId))
            ->select(
                DB::raw('SUM(CASE WHEN quantity_change < 0 AND type IN (\'ecommerce_order\',\'pos_order\',\'store_order\') THEN ABS(quantity_change) ELSE 0 END) as total_sold'),
                DB::raw('SUM(CASE WHEN type = \'return\' THEN quantity_change ELSE 0 END) as total_returned'),
                DB::raw('SUM(CASE WHEN type = \'transfer_out\' THEN ABS(quantity_change) ELSE 0 END) as total_transferred_out'),
                DB::raw('SUM(CASE WHEN type = \'transfer_in\' THEN quantity_change ELSE 0 END) as total_transferred_in')
            )
            ->first();

        return response()->json([
            'product'           => $product,
            'days'              => $days,
            'summary'           => [
                'total_sold'           => (int) ($totals->total_sold ?? 0),
                'total_returned'       => (int) ($totals->total_returned ?? 0),
                'total_transferred_out'=> (int) ($totals->total_transferred_out ?? 0),
                'total_transferred_in' => (int) ($totals->total_transferred_in ?? 0),
            ],
            'daily_trend'       => $dailyTrend,
            'channel_breakdown' => $channelBreakdown,
            'shop_breakdown'    => $shopBreakdown,
            'shop_stock'        => $shopStock,
            'movements'         => $movements,
        ]);
    }

    private function badge(int $heat, int $stock, int $discountPercent): string
    {
        if ($heat >= 75)                        return 'hot';
        if ($heat >= 45)                        return 'trending';
        if ($stock <= 5 && $stock > 0)          return 'low_stock';
        if ($heat <= 15 && $discountPercent < 5) return 'discount_candidate';
        return 'normal';
    }
}
