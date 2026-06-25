<?php

namespace App\Http\Controllers;

use App\Manager\PriceManager;
use App\Manager\ReportManager;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(): JsonResponse
    {
        $reportManager = new ReportManager(auth());
        $today = [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];

        // Combined sales: orders table (POS + ecom) + completed store_orders
        $storeSalesTotal = DB::table('store_orders')->where('status', 'completed')->sum('total_amount');
        $storeSalesToday = DB::table('store_orders')->where('status', 'completed')->whereBetween('created_at', $today)->sum('total_amount');
        $totalSales      = $reportManager->total_sale + (int) $storeSalesTotal;
        $totalSalesToday = $reportManager->total_sale_today + (int) $storeSalesToday;

        // Gross profit = total revenue - cost of goods sold (COGS)
        // COGS approximated from stock_ledger: units sold × product cost
        $cogs = DB::table('stock_ledger as sl')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->whereIn('sl.type', ['pos_order', 'ecommerce_order', 'store_order'])
            ->where('sl.quantity_change', '<', 0)
            ->sum(DB::raw('ABS(sl.quantity_change) * p.cost'));
        $cogsToday = DB::table('stock_ledger as sl')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->whereIn('sl.type', ['pos_order', 'ecommerce_order', 'store_order'])
            ->where('sl.quantity_change', '<', 0)
            ->whereBetween('sl.created_at', $today)
            ->sum(DB::raw('ABS(sl.quantity_change) * p.cost'));
        $grossProfit      = $totalSales - (int) $cogs;
        $grossProfitToday = $totalSalesToday - (int) $cogsToday;

        $report = [
            'total_product'        => $reportManager->total_product,
            'total_stock'          => $reportManager->total_stock,
            'low_stock'            => $reportManager->low_stock,
            'buy_value'            => PriceManager::priceFormat($reportManager->buying_stock_price),
            'sale_value'           => PriceManager::priceFormat($reportManager->saleing_stock_price),
            'possible_profit'      => PriceManager::priceFormat($reportManager->possible_profit),
            'total_sales'          => PriceManager::priceFormat($totalSales),
            'total_sales_today'    => PriceManager::priceFormat($totalSalesToday),
            'total_purchase'       => PriceManager::priceFormat($reportManager->buying_stock_price),
            'total_purchase_today' => PriceManager::priceFormat($reportManager->total_purchase_today),
            'total_expense'        => PriceManager::priceFormat((int) $cogs),
            'total_expense_today'  => PriceManager::priceFormat((int) $cogsToday),
            'total_profit'         => PriceManager::priceFormat($grossProfit),
            'total_profit_today'   => PriceManager::priceFormat($grossProfitToday),
        ];
        return response()->json($report);
    }

    public function monthlySales(Request $request): JsonResponse
    {
        $isAdmin = $request->user('sanctum')?->hasRole('admin') ?? false;
        $userId  = $request->user('sanctum')?->id ?? 0;
        $since   = Carbon::now()->subMonths(12)->startOfMonth();

        // POS + ecom orders
        $posQuery = DB::table('orders')
            ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"), DB::raw('SUM(total) as total'))
            ->where('created_at', '>=', $since)
            ->groupBy('month');
        if (!$isAdmin && $userId) {
            $posQuery->where('staff_user_id', $userId);
        }

        // In-store (counter) orders
        $storeQuery = DB::table('store_orders')
            ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"), DB::raw('SUM(total_amount) as total'))
            ->where('status', 'completed')
            ->where('created_at', '>=', $since)
            ->groupBy('month');

        // Merge both result sets by month
        $pos   = $posQuery->get()->keyBy('month');
        $store = $storeQuery->get()->keyBy('month');
        $months = $pos->keys()->merge($store->keys())->unique()->sort()->values();

        $data = $months->map(fn ($m) => [
            'month'           => $m,
            'total'           => (int)($pos[$m]->total ?? 0) + (int)($store[$m]->total ?? 0),
            'total_formatted' => PriceManager::priceFormat((int)($pos[$m]->total ?? 0) + (int)($store[$m]->total ?? 0)),
        ])->values();

        return response()->json($data);
    }

    public function monthlyPurchase(Request $request): JsonResponse
    {
        $user    = $request->user('sanctum');
        $isAdmin = $user?->hasRole('admin') ?? false;
        $shopId  = null;
        if ($user && !$isAdmin) {
            $primary = $user->primaryShop();
            $shopId  = $primary?->id;
        }

        $query = DB::table('store_orders')
            ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"), DB::raw('SUM(total_amount) as total'))
            ->where('created_at', '>=', Carbon::now()->subMonths(12)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month');

        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }

        $rows = $query->get();
        $data = $rows->map(fn ($r) => [
            'month' => $r->month,
            'total' => (int) $r->total,
            'total_formatted' => PriceManager::priceFormat((int) $r->total),
        ])->values();
        return response()->json($data);
    }

    public function salesTodayByBranch(Request $request): JsonResponse
    {
        $isAdmin = $request->user('sanctum')?->hasRole('admin') ?? false;
        $userId  = $request->user('sanctum')?->id ?? 0;
        $today   = [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()];

        // POS + ecom orders
        $posQuery = DB::table('orders')
            ->join('shops', 'shops.id', '=', 'orders.shop_id')
            ->select('orders.shop_id', 'shops.name as shop_name', DB::raw('SUM(orders.total) as total'))
            ->whereBetween('orders.created_at', $today)
            ->groupBy('orders.shop_id', 'shops.name');
        if (!$isAdmin && $userId) {
            $posQuery->where('orders.staff_user_id', $userId);
        }

        // In-store (counter) orders
        $storeQuery = DB::table('store_orders')
            ->join('shops', 'shops.id', '=', 'store_orders.shop_id')
            ->select('store_orders.shop_id', 'shops.name as shop_name', DB::raw('SUM(store_orders.total_amount) as total'))
            ->where('store_orders.status', 'completed')
            ->whereBetween('store_orders.created_at', $today)
            ->groupBy('store_orders.shop_id', 'shops.name');

        $pos   = $posQuery->get()->keyBy('shop_id');
        $store = $storeQuery->get()->keyBy('shop_id');
        $shopIds = collect($pos->keys())->merge($store->keys())->unique()->values();

        $data = $shopIds->map(function ($shopId) use ($pos, $store) {
            $shopName = $pos[$shopId]->shop_name ?? $store[$shopId]->shop_name;
            $total    = (int)($pos[$shopId]->total ?? 0) + (int)($store[$shopId]->total ?? 0);
            return [
                'shop_id'         => (int) $shopId,
                'shop_name'       => $shopName,
                'total'           => $total,
                'total_formatted' => PriceManager::priceFormat($total),
            ];
        })->values();

        return response()->json($data);
    }

    public function shopStockSummary(Request $request): JsonResponse
    {
        $user    = $request->user('sanctum');
        $isAdmin = $user?->hasRole('admin') ?? false;
        $shopId  = null;
        if ($user && !$isAdmin) {
            $primary = $user->primaryShop();
            $shopId  = $primary?->id;
        }

        $query = DB::table('shop_product')
            ->join('shops', 'shops.id', '=', 'shop_product.shop_id')
            ->select('shop_product.shop_id', 'shops.name as shop_name', DB::raw('SUM(shop_product.quantity) as total_stock'))
            ->groupBy('shop_product.shop_id', 'shops.name');

        if ($shopId !== null) {
            $query->where('shop_product.shop_id', $shopId);
        }

        $rows = $query->get();
        $data = $rows->map(fn ($r) => [
            'shop_id' => (int) $r->shop_id,
            'shop_name' => $r->shop_name,
            'total_stock' => (int) $r->total_stock,
        ])->values();
        return response()->json($data);
    }

    public function salesTrend(Request $request): JsonResponse
    {
        $days    = max(1, (int) $request->input('days', 14));
        $user    = $request->user('sanctum');
        $isAdmin = $user?->hasRole('admin') ?? false;
        $shopId  = null;
        if (!$isAdmin && $user) {
            $primary = $user->primaryShop();
            $shopId  = $primary?->id;
        }

        $start = Carbon::now()->subDays($days - 1)->startOfDay();

        // POS + ecom orders
        $posQuery = DB::table('orders')
            ->select(DB::raw("DATE(created_at) as day"), DB::raw('SUM(total) as amount'))
            ->where('order_status', Order::STATUS_COMPLETED)
            ->where('created_at', '>=', $start)
            ->groupBy('day');
        if ($shopId !== null) {
            $posQuery->where('shop_id', $shopId);
        }

        // In-store orders
        $storeQuery = DB::table('store_orders')
            ->select(DB::raw("DATE(created_at) as day"), DB::raw('SUM(total_amount) as amount'))
            ->where('status', 'completed')
            ->where('created_at', '>=', $start)
            ->groupBy('day');
        if ($shopId !== null) {
            $storeQuery->where('shop_id', $shopId);
        }

        $pos   = $posQuery->get()->keyBy('day');
        $store = $storeQuery->get()->keyBy('day');

        $data = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date   = Carbon::now()->subDays($i);
            $key    = $date->toDateString();
            $data[] = [
                'date'   => $date->format('d M'),
                'amount' => (int)($pos[$key]->amount ?? 0) + (int)($store[$key]->amount ?? 0),
            ];
        }

        return response()->json($data);
    }

    public function orderStatus(Request $request): JsonResponse
    {
        $user    = $request->user('sanctum');
        $isAdmin = $user?->hasRole('admin') ?? false;
        $shopId  = null;
        if (!$isAdmin && $user) {
            $primary = $user->primaryShop();
            $shopId  = $primary?->id;
        }

        // POS + ecom orders (numeric statuses)
        $posQuery = DB::table('orders')->select('order_status', DB::raw('COUNT(*) as count'));
        if ($shopId !== null) {
            $posQuery->where('shop_id', $shopId);
        }
        $pos = $posQuery->groupBy('order_status')->get()->keyBy('order_status');
        $get = fn(int $s): int => isset($pos[$s]) ? (int) $pos[$s]->count : 0;

        // In-store orders (string statuses)
        $storeQuery = DB::table('store_orders')->select('status', DB::raw('COUNT(*) as count'));
        if ($shopId !== null) {
            $storeQuery->where('shop_id', $shopId);
        }
        $store = $storeQuery->groupBy('status')->get()->keyBy('status');
        $getStore = fn(string $s): int => isset($store[$s]) ? (int) $store[$s]->count : 0;

        $result = [
            'pending'   => $get(Order::STATUS_PENDING)   + $getStore('pending'),
            'processed' => $get(Order::STATUS_PROCESSED),
            'completed' => $get(Order::STATUS_COMPLETED) + $getStore('completed'),
            'cancelled' => $get(Order::STATUS_CANCELLED) + $getStore('cancelled'),
        ];

        return response()->json($result);
    }

    public function topProducts(Request $request): JsonResponse
    {
        $days    = max(1, (int) $request->input('days', 30));
        $limit   = max(1, (int) $request->input('limit', 10));
        $user    = $request->user('sanctum');
        $isAdmin = $user?->hasRole('admin') ?? false;
        $shopId  = null;
        if (!$isAdmin && $user) {
            $primary = $user->primaryShop();
            $shopId  = $primary?->id;
        }

        $start = Carbon::now()->subDays($days)->startOfDay();

        // POS + ecom orders
        $posQuery = DB::table('order_details')
            ->join('orders', 'orders.id', '=', 'order_details.order_id')
            ->join('products', 'products.id', '=', 'order_details.product_id')
            ->select('products.id', 'products.name', DB::raw('SUM(order_details.quantity) as sold'), DB::raw('SUM(order_details.quantity * order_details.sale_price) as revenue'))
            ->whereNotNull('order_details.product_id')
            ->where('orders.order_status', Order::STATUS_COMPLETED)
            ->where('orders.created_at', '>=', $start)
            ->groupBy('products.id', 'products.name');
        if ($shopId !== null) {
            $posQuery->where('orders.shop_id', $shopId);
        }

        // In-store orders (no sale_price column — use product.price as approximation)
        $storeQuery = DB::table('store_order_details')
            ->join('store_orders', 'store_orders.id', '=', 'store_order_details.store_order_id')
            ->join('products', 'products.id', '=', 'store_order_details.product_id')
            ->select('products.id', 'products.name', DB::raw('SUM(store_order_details.quantity) as sold'), DB::raw('SUM(store_order_details.quantity * products.price) as revenue'))
            ->whereNotNull('store_order_details.product_id')
            ->where('store_orders.status', 'completed')
            ->where('store_orders.created_at', '>=', $start)
            ->groupBy('products.id', 'products.name');
        if ($shopId !== null) {
            $storeQuery->where('store_orders.shop_id', $shopId);
        }

        $pos   = $posQuery->get()->keyBy('id');
        $store = $storeQuery->get()->keyBy('id');
        $allIds = collect($pos->keys())->merge($store->keys())->unique();

        $data = $allIds->map(function ($id) use ($pos, $store) {
            return [
                'id'      => (int) $id,
                'name'    => $pos[$id]->name ?? $store[$id]->name,
                'sold'    => (int)($pos[$id]->sold ?? 0) + (int)($store[$id]->sold ?? 0),
                'revenue' => (int)($pos[$id]->revenue ?? 0) + (int)($store[$id]->revenue ?? 0),
            ];
        })->sortByDesc('sold')->take($limit)->values();

        return response()->json($data);
    }

    public function lowStockDetail(Request $request): JsonResponse
    {
        $threshold = (int) $request->input('threshold', ReportManager::LOW_STOCK_ALERT);
        $productQtys = DB::table('shop_product')
            ->select('product_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id')
            ->havingRaw('SUM(quantity) <= ?', [$threshold])
            ->pluck('total', 'product_id');

        if ($productQtys->isEmpty()) {
            return response()->json([]);
        }

        $products = DB::table('products')
            ->whereIn('id', $productQtys->keys())
            ->select('id', 'name', 'sku', 'cost', 'price')
            ->get()
            ->keyBy('id');

        $data = $productQtys->map(fn ($qty, $productId) => [
            'product_id' => (int) $productId,
            'name' => $products->get($productId)->name ?? '',
            'sku' => $products->get($productId)->sku ?? '',
            'total_quantity' => (int) $qty,
            'threshold' => $threshold,
        ])->values();
        return response()->json($data);
    }
}
