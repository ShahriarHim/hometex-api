<?php

namespace App\Http\Controllers;

use App\Manager\PriceManager;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopProduct;
use App\Models\StockLedger;
use App\Models\StoreOrder;
use App\Models\StoreOrderDetail;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReturnController extends Controller
{
    /**
     * Paginated return log — all submitted returns.
     * Query: page, per_page, search (product name / SKU / order id).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) ($request->input('per_page', 20));
        $search  = $request->input('search');

        $query = StockLedger::with(['product:id,name,sku', 'shop:id,name', 'createdByUser:id,first_name,last_name'])
            ->where('type', StockLedger::TYPE_RETURN)
            ->orderByDesc('created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%"))
                  ->orWhere('reference_id', is_numeric($search) ? (int) $search : null);
            });
        }

        $paginated = $query->paginate($perPage);

        $items = $paginated->getCollection()->map(fn ($t) => [
            'id'             => $t->id,
            'product'        => $t->product ? ['id' => $t->product->id, 'name' => $t->product->name, 'sku' => $t->product->sku] : null,
            'shop'           => $t->shop ? ['id' => $t->shop->id, 'name' => $t->shop->name] : null,
            'quantity'       => $t->quantity_change,
            'reference_type' => class_basename($t->reference_type ?? ''),
            'reference_id'   => $t->reference_id,
            'notes'          => $t->notes,
            'created_by'     => $t->createdByUser ? trim($t->createdByUser->first_name . ' ' . $t->createdByUser->last_name) : null,
            'created_at'     => $t->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'from'         => $paginated->firstItem(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * Search orders by phone or order_id (ecommerce order_number/id or store order id).
     * Query: phone (string) OR order_id (string) to search both types.
     */
    public function searchOrders(Request $request): JsonResponse
    {
        $phone = $request->input('phone');
        $orderId = $request->input('order_id');
        if (empty($phone) && empty($orderId)) {
            return response()->json([
                'success' => false,
                'message' => 'Provide phone or order_id',
            ], 400);
        }

        $returnedRefs = StockLedger::where('type', StockLedger::TYPE_RETURN)
            ->whereIn('reference_type', [Order::class, StoreOrder::class])
            ->get(['reference_type', 'reference_id'])
            ->keyBy(fn ($t) => $t->reference_type . '#' . $t->reference_id)
            ->keys()
            ->flip()
            ->toArray();

        $ecommerce = [];
        $store = [];

        $withReturn = function ($o, string $type) use ($returnedRefs) {
            $ref = ($type === 'ecommerce' ? Order::class : StoreOrder::class) . '#' . $o->id;
            return isset($returnedRefs[$ref]);
        };

        if (!empty($phone)) {
            $customerIds = Customer::where('phone', 'like', '%' . $phone . '%')->pluck('id');
            $ecommerce = Order::with(['customer:id,name,phone', 'shop:id,name'])
                ->whereIn('customer_id', $customerIds)
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'type' => 'ecommerce',
                    'customer' => $o->customer ? ['name' => $o->customer->name, 'phone' => $o->customer->phone] : null,
                    'shop' => $o->shop ? ['id' => $o->shop->id, 'name' => $o->shop->name] : null,
                    'total' => $o->total,
                    'created_at' => $o->created_at?->toIso8601String(),
                    'has_return' => $withReturn($o, 'ecommerce'),
                ]);

            $store = StoreOrder::with('shop:id,name')
                ->where('customer_number', 'like', '%' . $phone . '%')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'order_number' => (string) $o->id,
                    'type' => 'store',
                    'customer' => ['name' => null, 'phone' => $o->customer_number],
                    'shop' => $o->shop ? ['id' => $o->shop->id, 'name' => $o->shop->name] : null,
                    'total' => $o->total_amount,
                    'created_at' => $o->created_at?->toIso8601String(),
                    'has_return' => $withReturn($o, 'store'),
                ]);
        }

        if (!empty($orderId)) {
            $ecommerceById = Order::with(['customer:id,name,phone', 'shop:id,name'])
                ->where('id', (int) $orderId)
                ->orWhere('order_number', 'like', '%' . $orderId . '%')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'order_number' => $o->order_number,
                    'type' => 'ecommerce',
                    'customer' => $o->customer ? ['name' => $o->customer->name, 'phone' => $o->customer->phone] : null,
                    'shop' => $o->shop ? ['id' => $o->shop->id, 'name' => $o->shop->name] : null,
                    'total' => $o->total,
                    'created_at' => $o->created_at?->toIso8601String(),
                    'has_return' => $withReturn($o, 'ecommerce'),
                ]);
            $storeById = StoreOrder::with('shop:id,name')
                ->where('id', (int) $orderId)
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'order_number' => (string) $o->id,
                    'type' => 'store',
                    'customer' => ['name' => null, 'phone' => $o->customer_number],
                    'shop' => $o->shop ? ['id' => $o->shop->id, 'name' => $o->shop->name] : null,
                    'total' => $o->total_amount,
                    'created_at' => $o->created_at?->toIso8601String(),
                    'has_return' => $withReturn($o, 'store'),
                ]);
            $ecommerce = $ecommerceById->isEmpty() ? $ecommerce : $ecommerceById->merge($ecommerce)->unique('id')->values();
            $store = $storeById->isEmpty() ? $store : $storeById->merge($store)->unique('id')->values();
        }

        $orders = $ecommerce->concat($store)->sortByDesc('id')->values()->all();
        return response()->json(['success' => true, 'data' => $orders]);
    }

    /**
     * Get order line items for return UI (ordered items - left side).
     * Query: order_type = ecommerce|store, order_id = id.
     */
    public function getOrderDetails(Request $request): JsonResponse
    {
        $request->validate([
            'order_type' => 'required|in:ecommerce,store',
            'order_id' => 'required|integer',
        ]);
        $orderType = $request->input('order_type');
        $orderId = (int) $request->input('order_id');

        if ($orderType === 'store') {
            $order = StoreOrder::with('details.product')->find($orderId);
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Store order not found'], 404);
            }
            $items = $order->details->map(function ($d) {
                $product = $d->product;
                $unitPrice = 0;
                if ($product) {
                    $priceData = PriceManager::calculate_sell_price(
                        $product->price ?? 0,
                        $product->discount_percent ?? 0,
                        $product->discount_fixed ?? 0,
                        $product->discount_start ?? null,
                        $product->discount_end ?? null
                    );
                    $unitPrice = (int) ($priceData['price'] ?? $product->price ?? 0);
                }
                return [
                    'id' => $d->id,
                    'detail_id' => $d->id,
                    'product_id' => $d->product_id,
                    'name' => $product?->name ?? 'Product #' . $d->product_id,
                    'sku' => $product?->sku ?? null,
                    'quantity' => $d->quantity,
                    'max_return' => $d->quantity,
                    'unit_price' => $unitPrice,
                ];
            })->values()->all();
            $order_info = [
                'id' => $order->id,
                'order_number' => (string) $order->id,
                'type' => 'store',
                'shop' => $order->shop ? ['id' => $order->shop->id, 'name' => $order->shop->name] : null,
            ];
        } else {
            $order = Order::with('order_details')->find($orderId);
            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }
            $items = [];
            foreach ($order->order_details as $d) {
                $productId = $d->product_id ?? (is_string($d->sku) && $d->sku !== '' ? Product::where('sku', $d->sku)->value('id') : null);
                $unitPrice = (int) ($d->sale_price ?? $d->price ?? 0);
                $items[] = [
                    'id' => $d->id,
                    'detail_id' => $d->id,
                    'product_id' => $productId,
                    'name' => $d->name ?? 'Item',
                    'sku' => $d->sku ?? null,
                    'quantity' => $d->quantity,
                    'max_return' => $d->quantity,
                    'unit_price' => $unitPrice,
                ];
            }
            $order_info = [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'type' => 'ecommerce',
                'shop' => $order->shop ? ['id' => $order->shop->id, 'name' => $order->shop->name] : null,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order' => $order_info,
                'items' => $items,
            ],
        ]);
    }

    /**
     * Submit return: add quantities to selected shop and log transaction.
     * Body: order_type, order_id, return_to_shop_id, items: [{ detail_id, quantity }].
     */
    public function submitReturn(Request $request): JsonResponse
    {
        $request->validate([
            'order_type' => 'required|in:ecommerce,store',
            'order_id' => 'required|integer',
            'return_to_shop_id' => 'required|exists:shops,id',
            'items' => 'required|array',
            'items.*.detail_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $orderType = $request->input('order_type');
        $orderId = (int) $request->input('order_id');
        $returnToShopId = (int) $request->input('return_to_shop_id');
        $items = $request->input('items');

        try {
            DB::beginTransaction();

            if ($orderType === 'store') {
                $order = StoreOrder::with('details')->findOrFail($orderId);
                foreach ($items as $item) {
                    $detail = $order->details->firstWhere('id', (int) $item['detail_id']);
                    if (!$detail || $detail->product_id === null) {
                        continue;
                    }
                    $qty = min((int) $item['quantity'], $detail->quantity);
                    if ($qty <= 0) {
                        continue;
                    }
                    $this->addQuantityToShop($returnToShopId, $detail->product_id, $qty, $orderType, $orderId);
                }
            } else {
                $order = Order::with('order_details')->findOrFail($orderId);
                foreach ($items as $item) {
                    $detail = $order->order_details->firstWhere('id', (int) $item['detail_id']);
                    if (!$detail) {
                        continue;
                    }
                    $productId = $detail->product_id ?? (is_string($detail->sku) && $detail->sku !== '' ? Product::where('sku', $detail->sku)->value('id') : null);
                    if (!$productId) {
                        continue;
                    }
                    $qty = min((int) $item['quantity'], $detail->quantity);
                    if ($qty <= 0) {
                        continue;
                    }
                    $this->addQuantityToShop($returnToShopId, $productId, $qty, $orderType, $orderId);
                }
            }

            DB::commit();
            CacheService::clearProductCaches();
            return response()->json([
                'status' => 'success',
                'message' => 'Return processed successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Return failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function addQuantityToShop(int $shopId, int $productId, int $quantity, string $orderType, int $orderId): void
    {
        $pivot = ShopProduct::where('shop_id', $shopId)->where('product_id', $productId)->first();
        if ($pivot) {
            $pivot->increment('quantity', $quantity);
        } else {
            ShopProduct::create([
                'shop_id' => $shopId,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }
        $product = Product::find($productId);
        if ($product && $product->getAttribute('stock') !== null) {
            $product->increment('stock', $quantity);
        }
        $refType = $orderType === 'store' ? StoreOrder::class : Order::class;
        StockLedger::create([
            'shop_id' => $shopId,
            'product_id' => $productId,
            'quantity_change' => $quantity,
            'type' => StockLedger::TYPE_RETURN,
            'reference_type' => $refType,
            'reference_id' => $orderId,
            'created_by' => request()->user()?->id,
            'notes' => 'Customer return',
        ]);
    }
}
