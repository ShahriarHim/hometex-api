<?php

namespace App\Http\Controllers;

use App\Models\StockLedger;
use App\Models\StoreOrder;
use App\Models\StoreOrderDetail;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StoreOrderController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $orderBy = $request->input('order_by', 'id');
        $direction = strtolower($request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = (int) $request->input('per_page', 15);

        $allowedOrderBy = ['id', 'customer_number', 'total_amount', 'status', 'created_at', 'updated_at'];
        if (!in_array($orderBy, $allowedOrderBy)) {
            $orderBy = 'id';
        }

        $query = StoreOrder::with('details.product');

        if (trim((string) $search) !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('id', $search)
                    ->orWhere('customer_number', 'LIKE', '%' . $search . '%');
            });
        }

        // Branch-scoped: if the staff member is assigned to a specific shop,
        // they can only see orders from that shop — ignore the request shop_id filter.
        $assignedShopId = $request->user('sanctum')?->assignedShopId();
        if ($assignedShopId) {
            $query->where('shop_id', $assignedShopId);
        } else {
            $shopId = $request->input('shop_id');
            if ($shopId !== null && $shopId !== '') {
                $query->where('shop_id', (int) $shopId);
            }
        }

        $query->orderBy($orderBy, $direction);

        $orders = $query->paginate($perPage);
        return response()->json($orders);
    }

    public function store(Request $request)
    {
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'carts' => 'required|array',
            'carts.*.productId' => 'required|exists:products,id',
            'carts.*.quantity' => 'required|integer|min:1',
            'order_summary' => 'required|array',
        ]);

        $assignedShopId = $request->user('sanctum')?->assignedShopId();
        if ($assignedShopId && (int) $request->shop_id !== $assignedShopId) {
            return response()->json(['status' => 'error', 'message' => 'You can only create orders for your assigned branch.'], 403);
        }

        try {
            DB::beginTransaction();

            $createdAt = now();
            if ($request->created_at) {
                try {
                    $createdAt = Carbon::parse($request->created_at);
                } catch (\Throwable $e) {
                    $createdAt = now();
                }
            }

            $createdById = null;
            if ($request->created_by !== null && $request->created_by !== '') {
                if (is_numeric($request->created_by)) {
                    $createdById = (int) $request->created_by;
                }
            }

            $order = StoreOrder::create([
                'created_by' => $createdById,
                'shop_id' => $request->shop_id,
                'customer_number' => $request->order_summary['customer_number'] ?? null,
                'subtotal' => $request->order_summary['subtotal'] ?? 0,
                'discount_amount' => $request->order_summary['discount_amount'] ?? 0,
                'tax_amount' => $request->order_summary['tax_amount'] ?? 0,
                'total_amount' => $request->order_summary['total_amount'] ?? 0,
                'paid_amount' => $request->order_summary['paid_amount'] ?? 0,
                'due_amount' => $request->order_summary['due_amount'] ?? 0,
                'payment_method_id' => $request->order_summary['payment_method_id'] ?? null,
                'status' => $request->order_summary['status'] ?? 'completed',
                'notes' => $request->order_summary['notes'] ?? null,
                'created_at' => $createdAt,
            ]);

            foreach ($request->carts as $cart) {
                StoreOrderDetail::create([
                    'store_order_id' => $order->id,
                    'product_id' => $cart['productId'],
                    'quantity' => $cart['quantity'],
                ]);

                // Reduce shop product quantity
                $shopProduct = DB::table('shop_product')
                    ->where('shop_id', $request->shop_id)
                    ->where('product_id', $cart['productId'])
                    ->first();

                if ($shopProduct) {
                    DB::table('shop_product')
                        ->where('id', $shopProduct->id)
                        ->decrement('quantity', $cart['quantity']);
                }

                // Reduce the main product total stock
                $product = \App\Models\Product::find($cart['productId']);
                if ($product) {
                    $product->stock -= $cart['quantity'];
                    $product->save(); // This triggers observers to clear the cache
                }

                StockLedger::create([
                    'shop_id'         => $request->shop_id,
                    'product_id'      => $cart['productId'],
                    'quantity_change' => -(int) $cart['quantity'],
                    'unit_price'      => $product?->price,
                    'type'            => StockLedger::TYPE_STORE_ORDER,
                    'reference_type'  => StoreOrder::class,
                    'reference_id'    => $order->id,
                    'created_by'      => null,
                ]);
            }

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Order placed successfully', 'order' => $order]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to place order: ' . $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        $order = StoreOrder::with([
            'details.product',
            'shop',
            'shop.address',
            'shop.address.division',
            'shop.address.district',
            'shop.address.area',
            'paymentMethod',
            'createdByUser',
        ])->findOrFail($id);
        return response()->json($order);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'shop_id' => 'required|exists:shops,id',
            'carts' => 'sometimes|array',
            'order_summary' => 'required|array',
        ]);

        try {
            DB::beginTransaction();

            $order = StoreOrder::findOrFail($id);

            $assignedShopId = $request->user('sanctum')?->assignedShopId();
            if ($assignedShopId && (int) $order->shop_id !== $assignedShopId) {
                return response()->json(['status' => 'error', 'message' => 'You can only edit orders from your assigned branch.'], 403);
            }

            // If carts are provided, we need to adjust stock and details
            if ($request->has('carts')) {
                // Restore original stock
                foreach ($order->details as $detail) {
                    $shopProduct = DB::table('shop_product')
                        ->where('shop_id', $order->shop_id)
                        ->where('product_id', $detail->product_id)
                        ->first();

                    if ($shopProduct) {
                        DB::table('shop_product')
                            ->where('id', $shopProduct->id)
                            ->increment('quantity', $detail->quantity);
                    }

                    // Restore the main product total stock
                    $productModel = \App\Models\Product::find($detail->product_id);
                    if ($productModel) {
                        $productModel->stock += $detail->quantity;
                        $productModel->save();
                    }

                    StockLedger::create([
                        'shop_id' => $order->shop_id,
                        'product_id' => $detail->product_id,
                        'quantity_change' => (int) $detail->quantity,
                        'type' => StockLedger::TYPE_RESTORE,
                        'reference_type' => StoreOrder::class,
                        'reference_id' => $order->id,
                        'created_by' => $request->user()?->id,
                        'notes' => 'Store order update: restored quantity',
                    ]);
                }

                // Delete old details
                $order->details()->delete();

                // Insert new details and reduce stock
                foreach ($request->carts as $cart) {
                    StoreOrderDetail::create([
                        'store_order_id' => $order->id,
                        'product_id' => $cart['productId'],
                        'quantity' => $cart['quantity'],
                    ]);

                    $shopProduct = DB::table('shop_product')
                        ->where('shop_id', $request->shop_id)
                        ->where('product_id', $cart['productId'])
                        ->first();

                    if ($shopProduct) {
                        DB::table('shop_product')
                            ->where('id', $shopProduct->id)
                            ->decrement('quantity', $cart['quantity']);
                    }

                    // Reduce the main product total stock
                    $productModel = \App\Models\Product::find($cart['productId']);
                    if ($productModel) {
                        $productModel->stock -= $cart['quantity'];
                        $productModel->save();
                    }

                    StockLedger::create([
                        'shop_id'         => $request->shop_id,
                        'product_id'      => $cart['productId'],
                        'quantity_change' => -(int) $cart['quantity'],
                        'unit_price'      => $productModel?->price,
                        'type'            => StockLedger::TYPE_STORE_ORDER,
                        'reference_type'  => StoreOrder::class,
                        'reference_id'    => $order->id,
                        'created_by'      => $request->user()?->id,
                    ]);
                }
            }

            CacheService::clearProductCaches();

            // Update order summaries
            $order->update([
                'shop_id' => $request->shop_id,
                'customer_number' => $request->order_summary['customer_number'] ?? $order->customer_number,
                'subtotal' => $request->order_summary['subtotal'] ?? $order->subtotal,
                'discount_amount' => $request->order_summary['discount_amount'] ?? $order->discount_amount,
                'tax_amount' => $request->order_summary['tax_amount'] ?? $order->tax_amount,
                'total_amount' => $request->order_summary['total_amount'] ?? $order->total_amount,
                'paid_amount' => $request->order_summary['paid_amount'] ?? $order->paid_amount,
                'due_amount' => $request->order_summary['due_amount'] ?? $order->due_amount,
                'payment_method_id' => $request->order_summary['payment_method_id'] ?? $order->payment_method_id,
                'status' => $request->order_summary['status'] ?? $order->status,
                'notes' => $request->order_summary['notes'] ?? $order->notes,
            ]);

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Order updated successfully', 'order' => $order]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to update order: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            DB::beginTransaction();
            $order = StoreOrder::findOrFail($id);

            $assignedShopId = $request->user('sanctum')?->assignedShopId();
            if ($assignedShopId && (int) $order->shop_id !== $assignedShopId) {
                return response()->json(['status' => 'error', 'message' => 'You can only cancel orders from your assigned branch.'], 403);
            }

            if ($order->status !== 'cancelled') {
                $order->status = 'cancelled';
                $order->cancelled_by = $request->input('cancelled_by', 'Store User');
                $order->reason = $request->input('reason', 'customer returned items');
                $order->save();

                // Restore stock
                foreach ($order->details as $detail) {
                   $shopProduct = DB::table('shop_product')
                    ->where('shop_id', $order->shop_id)
                    ->where('product_id', $detail->product_id)
                    ->first();

                   if ($shopProduct) {
                       DB::table('shop_product')
                        ->where('id', $shopProduct->id)
                        ->increment('quantity', $detail->quantity);
                   }

                   // Restore the main product total stock
                   $productModel = \App\Models\Product::find($detail->product_id);
                   if ($productModel) {
                       $productModel->stock += $detail->quantity;
                       $productModel->save();
                   }

                    StockLedger::create([
                        'shop_id' => $order->shop_id,
                        'product_id' => $detail->product_id,
                        'quantity_change' => (int) $detail->quantity,
                        'type' => StockLedger::TYPE_RESTORE,
                        'reference_type' => StoreOrder::class,
                        'reference_id' => $order->id,
                        'created_by' => $request->user()?->id,
                        'notes' => 'Order cancelled: restored quantity',
                    ]);
                }
            }

            CacheService::clearProductCaches();

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Order cancelled successfully']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to cancel order: ' . $e->getMessage()], 500);
        }
    }

    // Explicit cancel method if they prefer POST rather than DELETE
    public function cancel(Request $request) 
    {
        $id = $request->input('store_order_id');
        $request->validate([
            'store_order_id' => 'required|exists:store_orders,id',
            'cancelled_by' => 'required|string',
            'reason' => 'required|string',
            'status' => 'required|string'
        ]);

        return $this->destroy($request, $id);
    }
}
