<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderDetailsResource;
use App\Http\Resources\OrderListResource;
use App\Models\Order;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\OrderDetails;
use App\Models\OrderHistory;
use App\Models\Customer;
use App\Http\Resources\OrderHistoryResource;
use App\Models\StockLedger;
use App\Models\PaymentMethod;
use App\Models\UserAddress;
use App\Manager\OrderManager;
use App\Manager\PriceManager;
use App\Services\ActivityLogService;
use App\Services\CacheService;
use App\Services\SteadfastService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $orders = (new Order())->getAllOrders($request->all(), auth());
        return OrderListResource::collection($orders);
    }

    /**
     * @param StoreOrderRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreOrderRequest $request):JsonResponse
    {
        try {
            DB::beginTransaction();
        $order =(new Order)->placeOrder($request->all(), auth()->user());
        DB::commit();
            ActivityLogService::orderCreated($order->id, $order->invoice ?? (string) $order->id);
            return response()->json(['status' => 'success', 'message' => 'Order placed successfully', 'order_id' => $order->id]);
        }catch (\Throwable $e){
            info('ORDER_PLACED_FAILED', ['message'=>$e->getMessage()]);
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
        // return $request->all();
    }

    /**
     * Display the specified resource.
     */
    public function show($orderId): JsonResponse
    {
        try {
            $order = Order::with([
                'customer:id,name,phone,email',
                'payment_method:id,name',
                'staff_user:id,first_name,last_name',
                'shop:id,name',
                'order_details.brand:id,name',
                'order_details.category:id,name',
                'order_details.sub_category:id,name',
                'order_details.supplier:id,name',
                'order_details.attribute_value:id,name,attribute_id',
                'order_details.attribute_value.attribute:id,name',
                'transactions',
                'transactions.customer',
                'transactions.payment_method',
                'history',
            ])->find($orderId);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                    'order_id' => $orderId,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order details retrieved successfully',
                'data' => new OrderDetailsResource($order),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve order details: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark order as stock-adjusted (after reduce-all on adjustment page).
     */
    public function markAdjusted($orderId): JsonResponse
    {
        $order = Order::find($orderId);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Order not found'], 404);
        }
        try {
            $order->stock_adjusted_at = now();
            $order->save();
            return response()->json(['success' => true, 'message' => 'Order marked as adjusted', 'data' => ['stock_adjusted_at' => $order->stock_adjusted_at->toIso8601String()]]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark adjusted: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Count orders that need adjustment (stock_adjusted_at is null).
     */
    public function pendingAdjustmentCount(Request $request): JsonResponse
    {
        $query = Order::whereNull('stock_adjusted_at');
        $user  = $request->user('sanctum');
        if (! ($user?->hasRole('admin') ?? false)) {
            if ($user instanceof \App\Models\User) {
                $primaryShop = $user->primaryShop();
                if ($primaryShop) {
                    $query->where('shop_id', $primaryShop->id);
                }
            }
        }
        $count = $query->count();
        return response()->json(['count' => $count]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateOrderRequest $request, Order $order)
    {
        //
    }

    /**
     * Update order payment (paid_amount, due_amount, payment_status).
     */
    public function updatePayment(Request $request, Order $order): JsonResponse
    {
        $request->validate([
            'paid_amount' => 'required|numeric|min:0',
        ]);
        $oldPaid = (int) $order->paid_amount;
        $paid = (int) round((float) $request->paid_amount);
        $total = (int) $order->total;
        $due = max(0, $total - $paid);
        $order->paid_amount = $paid;
        $order->due_amount = $due;
        $order->payment_status = OrderManager::decidePaymentStatus($total, $paid);
        $order->save();
        OrderHistory::create([
            'order_id' => $order->id,
            'user_id' => auth()->id(),
            'action' => OrderHistory::ACTION_PAYMENT_UPDATED,
            'description' => "Payment updated: paid {$paid}, due {$due}",
            'old_values' => ['paid_amount' => $oldPaid, 'due_amount' => $order->due_amount + $paid - $oldPaid],
            'new_values' => ['paid_amount' => $paid, 'due_amount' => $due],
        ]);
        return response()->json([
            'success' => true,
            'message' => 'Payment updated',
            'data' => new OrderDetailsResource($order->load('history')->fresh()),
        ]);
    }

    public function updateAddress(Request $request, Order $order): JsonResponse
    {
        if ($order->order_status == Order::STATUS_CANCELLED) {
            return response()->json(['success' => false, 'message' => 'Cannot update cancelled order'], 422);
        }
        $old = $order->only([
            'shipping_name', 'shipping_phone', 'shipping_email', 'shipping_address_line_1', 'shipping_address_line_2',
            'shipping_city', 'shipping_state', 'shipping_postal_code', 'shipping_country',
            'billing_name', 'billing_phone', 'billing_email', 'billing_address_line_1', 'billing_address_line_2',
            'billing_city', 'billing_state', 'billing_postal_code', 'billing_country',
        ]);
        $allowed = array_keys($old);
        $input = $request->only($allowed);
        $order->fill($input);
        $order->save();
        OrderHistory::create([
            'order_id' => $order->id,
            'user_id' => auth()->id(),
            'action' => OrderHistory::ACTION_ADDRESS_UPDATED,
            'description' => 'Address updated',
            'old_values' => $old,
            'new_values' => $order->only($allowed),
        ]);
        return response()->json([
            'success' => true,
            'message' => 'Address updated',
            'data' => new OrderDetailsResource($order->load('history')->fresh()),
        ]);
    }

    public function addItem(Request $request, Order $order): JsonResponse
    {
        if ($order->order_status == Order::STATUS_CANCELLED) {
            return response()->json(['success' => false, 'message' => 'Cannot modify cancelled order'], 422);
        }
        $attrVal = $request->input('attribute_value_id');
        if ($attrVal === '' || $attrVal === null || $attrVal === 0 || $attrVal === '0') {
            $request->merge(['attribute_value_id' => null]);
        } elseif (is_numeric($attrVal)) {
            $request->merge(['attribute_value_id' => (int) $attrVal]);
        }
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:1',
            'attribute_value_id' => 'nullable|integer|exists:attribute_values,id',
        ]);
        $productId = (int) $request->product_id;
        $quantity = (int) round((float) $request->quantity);
        if ($quantity < 1) {
            return response()->json(['success' => false, 'message' => 'Quantity must be at least 1'], 422);
        }
        $attributeValueId = $request->input('attribute_value_id');
        $attributeValueId = ($attributeValueId !== null && $attributeValueId !== '' && (int) $attributeValueId > 0) ? (int) $attributeValueId : null;
        $product = (new Product())->getProductById($productId);
        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }
        if ($attributeValueId !== null) {
            $validAttr = ProductAttribute::where('product_id', $productId)->where('attribute_value_id', $attributeValueId)->exists();
            if (!$validAttr) {
                return response()->json(['success' => false, 'message' => 'Selected attribute does not belong to this product'], 422);
            }
        }
        $shopId = (int) $order->shop_id;
        $existingDetail = OrderDetails::where('order_id', $order->id)
            ->where('product_id', $productId)
            ->where(function ($q) use ($attributeValueId) {
                if ($attributeValueId === null) {
                    $q->whereNull('attribute_value_id');
                } else {
                    $q->where('attribute_value_id', $attributeValueId);
                }
            })
            ->first();

        if ($existingDetail) {
            $existingQty = (int) $existingDetail->quantity;
            $newQty = $existingQty + $quantity;
            $row = DB::table('shop_product')->where('shop_id', $shopId)->where('product_id', $productId)->first();
            $available = $row ? (int) $row->quantity : 0;
            if ($available < $quantity) {
                return response()->json(['success' => false, 'message' => "Insufficient stock. Available: {$available}"], 422);
            }
            DB::beginTransaction();
            try {
                $existingDetail->update(['quantity' => $newQty]);
                OrderManager::decrementShopProductStock($shopId, $productId, $quantity);
                $this->recalcOrderTotals($order);
                OrderHistory::create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'action' => OrderHistory::ACTION_ITEM_UPDATED,
                    'description' => "Quantity updated: {$product->name} from {$existingQty} to {$newQty}",
                    'old_values' => ['detail_id' => $existingDetail->id, 'quantity' => $existingQty],
                    'new_values' => ['quantity' => $newQty],
                ]);
                DB::commit();
                $freshOrder = $order->fresh();
                $freshOrder->load(['order_details.brand', 'order_details.category', 'order_details.sub_category', 'order_details.supplier', 'order_details.attribute_value.attribute', 'history']);
                return response()->json([
                    'success' => true,
                    'message' => 'Item quantity updated',
                    'data' => new OrderDetailsResource($freshOrder),
                ]);
            } catch (\Throwable $e) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
            }
        }

        $row = DB::table('shop_product')->where('shop_id', $shopId)->where('product_id', $productId)->first();
        $available = $row ? (int) $row->quantity : 0;
        if ($available < $quantity) {
            return response()->json(['success' => false, 'message' => "Insufficient stock. Available: {$available}"], 422);
        }
        DB::beginTransaction();
        try {
            $sellPrice = \App\Manager\PriceManager::calculate_sell_price(
                $product->price,
                $product->discount_percent ?? 0,
                $product->discount_fixed ?? 0,
                $product->discount_start ?? null,
                $product->discount_end ?? null
            );
            $salePrice = (int) ($sellPrice['price'] ?? $product->price);
            $detailData = [
                'order_id' => $order->id,
                'product_id' => $productId,
                'attribute_value_id' => $attributeValueId,
                'name' => $product->name,
                'brand_id' => $product->brand_id,
                'category_id' => $product->category_id,
                'cost' => $product->cost ?? 0,
                'discount_end' => $product->discount_end,
                'discount_fixed' => $product->discount_fixed ?? 0,
                'discount_percent' => $product->discount_percent ?? 0,
                'discount_start' => $product->discount_start,
                'price' => $product->price ?? 0,
                'sale_price' => $salePrice,
                'sku' => $product->sku,
                'sub_category_id' => $product->sub_category_id,
                'child_sub_category_id' => $product->child_sub_category_id,
                'supplier_id' => $product->supplier_id,
                'quantity' => $quantity,
                'photo' => $product->primary_photo?->photo ?? null,
            ];
            OrderDetails::query()->create($detailData);
            OrderManager::decrementShopProductStock($shopId, $productId, $quantity);
            $this->recalcOrderTotals($order);
            $attrDesc = $attributeValueId ? ' (with attribute)' : '';
            OrderHistory::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'action' => OrderHistory::ACTION_ITEM_ADDED,
                'description' => "Added: {$product->name} x{$quantity}{$attrDesc}",
                'new_values' => ['product_id' => $productId, 'quantity' => $quantity, 'name' => $product->name, 'attribute_value_id' => $attributeValueId],
            ]);
            DB::commit();
            $freshOrder = $order->fresh();
            $freshOrder->load(['order_details.brand', 'order_details.category', 'order_details.sub_category', 'order_details.supplier', 'order_details.attribute_value.attribute', 'history']);
            return response()->json([
                'success' => true,
                'message' => 'Item added',
                'data' => new OrderDetailsResource($freshOrder),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateItem(Request $request, Order $order, $orderDetail): JsonResponse
    {
        $detail = is_object($orderDetail) ? $orderDetail : OrderDetails::find($orderDetail);
        if (!$detail || (int) $detail->order_id !== (int) $order->id) {
            return response()->json(['success' => false, 'message' => 'Detail not found or does not belong to this order'], 404);
        }
        if ($order->order_status == Order::STATUS_CANCELLED) {
            return response()->json(['success' => false, 'message' => 'Cannot modify cancelled order'], 422);
        }
        $quantity = $request->input('quantity') ?? $request->query('quantity');
        $request->merge(['quantity' => $quantity]);
        $request->validate(['quantity' => 'required|numeric|min:1']);
        $newQty = (int) round((float) $request->quantity);
        $oldQty = (int) $detail->quantity;
        if ($newQty === $oldQty) {
            $freshOrder = $order->fresh();
            $freshOrder->load(['order_details.brand', 'order_details.category', 'order_details.sub_category', 'order_details.supplier', 'order_details.attribute_value.attribute', 'history']);
            return response()->json(['success' => true, 'message' => 'No change', 'data' => new OrderDetailsResource($freshOrder)]);
        }
        $productId = $detail->product_id ?? \App\Models\Product::where('sku', $detail->sku)->value('id');
        $shopId = (int) $order->shop_id;
        $diff = $newQty - $oldQty;
        if ($diff > 0 && $productId && $shopId) {
            $row = DB::table('shop_product')->where('shop_id', $shopId)->where('product_id', $productId)->first();
            $available = $row ? (int) $row->quantity : 0;
            if ($available < $diff) {
                return response()->json(['success' => false, 'message' => "Insufficient stock. Available: {$available}"], 422);
            }
        }
        DB::beginTransaction();
        try {
            $detail->update(['quantity' => $newQty]);
            if ($productId && $shopId) {
                if ($diff > 0) {
                    OrderManager::decrementShopProductStock($shopId, $productId, $diff);
                } else {
                    DB::table('shop_product')
                        ->where('shop_id', $shopId)
                        ->where('product_id', $productId)
                        ->increment('quantity', -$diff);
                }
            }
            $this->recalcOrderTotals($order);
            OrderHistory::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'action' => OrderHistory::ACTION_ITEM_UPDATED,
                'description' => "Quantity updated: {$detail->name} from {$oldQty} to {$newQty}",
                'old_values' => ['detail_id' => $detail->id, 'quantity' => $oldQty],
                'new_values' => ['quantity' => $newQty],
            ]);
            DB::commit();
            $freshOrder = $order->fresh();
            $freshOrder->load(['order_details.brand', 'order_details.category', 'order_details.sub_category', 'order_details.supplier', 'order_details.attribute_value.attribute', 'history']);
            return response()->json([
                'success' => true,
                'message' => 'Quantity updated',
                'data' => new OrderDetailsResource($freshOrder),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function removeItem(Order $order, OrderDetails $orderDetail): JsonResponse
    {
        if ($order->id !== $orderDetail->order_id) {
            return response()->json(['success' => false, 'message' => 'Detail does not belong to this order'], 422);
        }
        if ($order->order_status == Order::STATUS_CANCELLED) {
            return response()->json(['success' => false, 'message' => 'Cannot modify cancelled order'], 422);
        }
        $productId = $orderDetail->product_id ?? \App\Models\Product::where('sku', $orderDetail->sku)->value('id');
        $quantity = (int) $orderDetail->quantity;
        $detailName = $orderDetail->name;
        $detailId = $orderDetail->id;
        $shopId = (int) $order->shop_id;
        DB::beginTransaction();
        try {
            $orderDetail->delete();
            if ($productId && $shopId) {
                DB::table('shop_product')
                    ->where('shop_id', $shopId)
                    ->where('product_id', $productId)
                    ->increment('quantity', $quantity);
            }
            $this->recalcOrderTotals($order);
            OrderHistory::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'action' => OrderHistory::ACTION_ITEM_REMOVED,
                'description' => "Removed: {$detailName} x{$quantity}",
                'old_values' => ['detail_id' => $detailId, 'name' => $detailName, 'quantity' => $quantity],
            ]);
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Item removed',
                'data' => new OrderDetailsResource($order->load(['order_details.brand', 'order_details.category', 'order_details.sub_category', 'order_details.supplier', 'order_details.attribute_value.attribute', 'history'])->fresh()),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function cancelOrder(Order $order): JsonResponse
    {
        if ($order->order_status == Order::STATUS_CANCELLED) {
            return response()->json(['success' => false, 'message' => 'Order already cancelled'], 422);
        }
        $shopId = (int) $order->shop_id;
        DB::beginTransaction();
        try {
            foreach ($order->order_details as $detail) {
                $productId = $detail->product_id ?? \App\Models\Product::where('sku', $detail->sku)->value('id');
                if ($productId && $shopId) {
                    DB::table('shop_product')
                        ->where('shop_id', $shopId)
                        ->where('product_id', $productId)
                        ->increment('quantity', (int) $detail->quantity);
                }
            }
            $order->order_status = Order::STATUS_CANCELLED;
            $order->save();
            OrderHistory::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'action' => OrderHistory::ACTION_ORDER_CANCELLED,
                'description' => 'Order cancelled',
            ]);
            DB::commit();
            ActivityLogService::orderCancelled($order->id, $order->invoice ?? (string) $order->id);
            return response()->json([
                'success' => true,
                'message' => 'Order cancelled',
                'data' => new OrderDetailsResource($order->load(['order_details.attribute_value.attribute', 'history'])->fresh()),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function recalcOrderTotals(Order $order): void
    {
        $order->load('order_details');
        $subTotal = 0;
        $total = 0;
        $qty = 0;
        foreach ($order->order_details as $d) {
            $price = (int) ($d->price ?? 0);
            $salePrice = (int) ($d->sale_price ?? $price);
            $q = (int) ($d->quantity ?? 0);
            $subTotal += $price * $q;
            $total += $salePrice * $q;
            $qty += $q;
        }
        $order->sub_total = $subTotal;
        $order->discount = $subTotal - $total;
        $order->total = $total;
        $order->quantity = $qty;
        $order->due_amount = max(0, $total - (int) $order->paid_amount);
        $order->payment_status = OrderManager::decidePaymentStatus($total, (int) $order->paid_amount);
        $order->save();
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order)
    {
        //
    }

    /**
     * Create a new order with the new API format
     * 
     * @param CreateOrderRequest $request
     * @return JsonResponse
     */
    public function createOrder(CreateOrderRequest $request): JsonResponse
    {
        try {
            DB::beginTransaction();

            $customerUserId = $request->input('customerId');
            $items = $request->input('items');
            $shippingAddress = $request->input('shippingAddress');
            $billingAddress = $request->input('billingAddress');
            $paymentMethod = $request->input('paymentMethod');
            $additionalDetails = $request->input('additionalDetails', []);

            $customer = Customer::where('user_id', $customerUserId)->first();
            
            if (!$customer) {
                $user = \App\Models\User::find($customerUserId);
                if (!$user) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'User not found',
                    ], 404);
                }
                
                $customer = Customer::create([
                    'user_id' => $customerUserId,
                    'name' => $user->first_name . ($user->last_name ? ' ' . $user->last_name : ''),
                    'email' => $user->email,
                    'phone' => $user->phone ?? '',
                ]);
            }
            
            $customerId = $customer->id;

            $subTotal = 0;
            $discount = 0;
            $total = 0;
            $quantity = 0;
            $orderDetailsData = [];

            foreach ($items as $item) {
                $productId = $item['id'];
                $itemQuantity = $item['quantity'];

                $product = Product::where('sku', $productId)
                    ->orWhere('id', $productId)
                    ->with('primary_photo')
                    ->first();

                if (!$product) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Product with ID/SKU '{$productId}' not found",
                        'success' => false
                    ], 404);
                }

                if ($product->stock < $itemQuantity) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Insufficient stock for product '{$product->name}'. Available: {$product->stock}, Requested: {$itemQuantity}",
                        'success' => false
                    ], 400);
                }

                $priceData = PriceManager::calculate_sell_price(
                    $product->price,
                    $product->discount_percent,
                    $product->discount_fixed,
                    $product->discount_start,
                    $product->discount_end
                );

                $itemSubTotal = $product->price * $itemQuantity;
                $itemDiscount = $priceData['discount'] * $itemQuantity;
                $itemTotal = $priceData['price'] * $itemQuantity;

                $subTotal += $itemSubTotal;
                $discount += $itemDiscount;
                $total += $itemTotal;
                $quantity += $itemQuantity;

                $product->quantity = $itemQuantity;
                $orderDetailsData[] = $product;

                $product->decrement('stock', $itemQuantity);
                OrderManager::decrementShopProductStock(OrderManager::ECOMMERCE_SHOP_ID, (int) $product->id, $itemQuantity);
            }

            $paymentMethodModel = PaymentMethod::where('name', 'like', '%' . $paymentMethod['type'] . '%')
                ->first();

            if (!$paymentMethodModel) {
                $paymentMethodModel = PaymentMethod::first();
            }

            if (!$paymentMethodModel) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Payment method not found',
                    'success' => false
                ], 400);
            }

            $shopId = 4;
            $staffUserId = null;

            $orderNumber = OrderManager::generateOrderNumber($shopId);

            $steadfastService = new SteadfastService();
            
            $itemDescriptions = collect($orderDetailsData)->map(function ($product) {
                return $product->name . ' (Qty: ' . $product->quantity . ')';
            })->implode(', ');

            $recipientData = $request->input('recipient', []);
            
            $recipientName = $recipientData['name'] 
                ?? $shippingAddress['name'] 
                ?? $request->input('shipping_address.name')
                ?? $customer->name;
            $recipientName = substr($recipientName, 0, 100);

            $recipientPhone = $recipientData['phone'] 
                ?? $shippingAddress['phone'] 
                ?? $request->input('shipping_address.phone')
                ?? $customer->phone ?? '01234567890';
            $recipientPhone = preg_replace('/[^0-9]/', '', $recipientPhone);
            if (strlen($recipientPhone) != 11) {
                $recipientPhone = '01234567890';
            }

            $alternativePhone = null;
            if (isset($recipientData['alternative_phone']) && !empty($recipientData['alternative_phone'])) {
                $alternativePhone = preg_replace('/[^0-9]/', '', $recipientData['alternative_phone']);
                if (strlen($alternativePhone) != 11) {
                    $alternativePhone = null;
                }
            }
            if (!$alternativePhone) {
                $alternativePhone = $recipientPhone;
            }

            $recipientAddress = $recipientData['address'] ?? null;
            if (!$recipientAddress) {
                $addressParts = array_filter([
                    $shippingAddress['street'],
                    $shippingAddress['city'],
                    $shippingAddress['state'] ?? null,
                    $shippingAddress['country'],
                    $shippingAddress['postalCode']
                ]);
                $recipientAddress = implode(', ', $addressParts);
            }
            if (strlen($recipientAddress) > 250) {
                $recipientAddress = substr($recipientAddress, 0, 247) . '...';
            }

            $recipientEmail = null;
            $emailCandidates = [
                $recipientData['email'] ?? null,
                $shippingAddress['email'] ?? null,
                $request->input('shipping_address.email'),
            ];
            
            foreach ($emailCandidates as $email) {
                if ($email && is_string($email)) {
                    $email = trim($email);
                    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $recipientEmail = $email;
                        break;
                    }
                }
            }
            
            if (!$recipientEmail) {
                if ($customer->user_id) {
                    $user = \App\Models\User::find($customer->user_id);
                    if ($user && $user->email) {
                        $userEmail = trim($user->email);
                        if (!empty($userEmail) && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                            $recipientEmail = $userEmail;
                        }
                    }
                }
                
                if (!$recipientEmail && $customer->email) {
                    $customerEmail = trim($customer->email);
                    if (!empty($customerEmail) && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                        $recipientEmail = $customerEmail;
                    }
                }
            }

            $deliveryType = (int) $request->input('delivery_type', 0);
            if (!in_array($deliveryType, [0, 1])) {
                $deliveryType = 0;
            }

            $steadfastOrderData = [
                'invoice' => $orderNumber,
                'recipient_name' => $recipientName,
                'recipient_phone' => $recipientPhone,
                'alternative_phone' => $alternativePhone,
                'recipient_address' => $recipientAddress,
                'cod_amount' => $total,
                'note' => isset($additionalDetails['notes']) ? substr($additionalDetails['notes'], 0, 500) : null,
                'item_description' => substr($itemDescriptions, 0, 500),
                'total_lot' => $quantity,
                'delivery_type' => $deliveryType,
            ];

            if ($recipientEmail && !empty(trim($recipientEmail)) && filter_var(trim($recipientEmail), FILTER_VALIDATE_EMAIL)) {
                $steadfastOrderData['recipient_email'] = trim($recipientEmail);
            }

            $steadfastResult = $steadfastService->createOrder($steadfastOrderData);

            if (!$steadfastResult['success']) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Order creation failed: Steadfast integration failed',
                    'error' => $steadfastResult['error'],
                    'steadfast_status_code' => $steadfastResult['status_code'] ?? null,
                ], 500);
            }

            $steadfastConsignment = $steadfastResult['data'];
            $consignmentId = $steadfastConsignment['consignment_id'] ?? null;
            $trackingCode = $steadfastConsignment['tracking_code'] ?? null;

            $rawPaymentType = strtolower((string) ($paymentMethod['type'] ?? ''));
            $isCod = in_array($rawPaymentType, [
                'cod',
                'cash_on_delivery',
                'cash on delivery',
            ], true);
            $paidAmount = $isCod ? 0 : $total;
            $dueAmount = $isCod ? $total : 0;
            $paymentStatus = $isCod ? Order::UNPAID : Order::PAID;

            $order = Order::create([
                'customer_id' => $customerId,
                'staff_user_id' => $staffUserId,
                'shop_id' => $shopId,
                'sub_total' => $subTotal,
                'discount' => $discount,
                'total' => $total,
                'quantity' => $quantity,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'order_status' => Order::STATUS_PENDING,
                'order_number' => $orderNumber,
                'payment_method_id' => $paymentMethodModel->id,
                'payment_status' => $paymentStatus,
                'shipment_status' => Order::SHIPMENT_STATUS_COMPLETED,
                'consignment_id' => $consignmentId,
                'tracking_code' => $trackingCode,
            ]);

            foreach ($orderDetailsData as $product) {
                OrderDetails::create([
                    'order_id' => $order->id,
                    'name' => $product->name,
                    'brand_id' => $product->brand_id,
                    'category_id' => $product->category_id,
                    'cost' => $product->cost,
                    'discount_end' => $product->discount_end,
                    'discount_fixed' => $product->discount_fixed,
                    'discount_percent' => $product->discount_percent,
                    'discount_start' => $product->discount_start,
                    'price' => $product->price,
                    'sale_price' => PriceManager::calculate_sell_price(
                        $product->price,
                        $product->discount_percent,
                        $product->discount_fixed,
                        $product->discount_start,
                        $product->discount_end
                    )['price'],
                    'sku' => $product->sku,
                    'sub_category_id' => $product->sub_category_id,
                    'child_sub_category_id' => $product->child_sub_category_id,
                    'supplier_id' => $product->supplier_id,
                    'quantity' => $product->quantity,
                    'photo' => $product->primary_photo?->photo,
                ]);
            }

            foreach ($orderDetailsData as $product) {
                StockLedger::create([
                    'shop_id'         => OrderManager::ECOMMERCE_SHOP_ID,
                    'product_id'      => $product->id,
                    'quantity_change' => -(int) $product->quantity,
                    'unit_price'      => $product->price,
                    'type'            => StockLedger::TYPE_ECOMMERCE_ORDER,
                    'reference_type'  => Order::class,
                    'reference_id'    => $order->id,
                    'created_by'      => auth()->id(),
                ]);
            }

            CacheService::clearProductCaches();

            if ($customer->user_id) {
                $shippingAddressString = json_encode($shippingAddress);
                $billingAddressString = json_encode($billingAddress);

                UserAddress::create([
                    'user_id' => $customer->user_id,
                    'address_type' => 'shipping',
                    'address_line_1' => $shippingAddress['street'],
                    'city' => $shippingAddress['city'],
                    'state' => $shippingAddress['state'] ?? null,
                    'postal_code' => $shippingAddress['postalCode'],
                    'country_code' => $this->getCountryCode($shippingAddress['country']),
                    'full_name' => $customer->name,
                    'phone' => $customer->phone ?? '',
                ]);

                if ($shippingAddressString !== $billingAddressString) {
                    UserAddress::create([
                        'user_id' => $customer->user_id,
                        'address_type' => 'billing',
                        'address_line_1' => $billingAddress['street'],
                        'city' => $billingAddress['city'],
                        'state' => $billingAddress['state'] ?? null,
                        'postal_code' => $billingAddress['postalCode'],
                        'country_code' => $this->getCountryCode($billingAddress['country']),
                        'full_name' => $customer->name,
                        'phone' => $customer->phone ?? '',
                    ]);
                }
            }

            DB::commit();

            $order->load(['customer', 'payment_method', 'order_details']);

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'data' => [
                    'order' => [
                        'id' => $order->id,
                        'orderNumber' => $order->order_number,
                        'customerId' => $order->customer_id,
                        'customer' => [
                            'id' => $order->customer->id,
                            'name' => $order->customer->name,
                            'email' => $order->customer->email,
                            'phone' => $order->customer->phone,
                        ],
                        'items' => $order->order_details->map(function ($detail) {
                            return [
                                'id' => $detail->id,
                                'name' => $detail->name,
                                'sku' => $detail->sku,
                                'quantity' => $detail->quantity,
                                'price' => $detail->price,
                                'salePrice' => $detail->sale_price,
                            ];
                        }),
                        'shippingAddress' => $shippingAddress,
                        'billingAddress' => $billingAddress,
                        'paymentMethod' => [
                            'type' => $paymentMethod['type'],
                            'cardNumber' => $paymentMethod['cardNumber'] ?? null,
                        ],
                        'subTotal' => $order->sub_total,
                        'discount' => $order->discount,
                        'total' => $order->total,
                        'quantity' => $order->quantity,
                        'orderStatus' => $order->order_status,
                        'paymentStatus' => $order->payment_status,
                        'consignmentId' => $order->consignment_id,
                        'trackingCode' => $order->tracking_code,
                        'createdAt' => $order->created_at->toISOString(),
                        'updatedAt' => $order->updated_at->toISOString(),
                    ],
                ],
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->getMessage(),
                'success' => false
            ], 404);
        } catch (\Throwable $e) {
            DB::rollBack();
            info('ORDER_CREATE_FAILED', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'Failed to create order: ' . $e->getMessage(),
                'success' => false
            ], 500);
        }
    }

    private function getCountryCode(string $countryName): string
    {
        $countryMap = [
            'Bangladesh' => 'BD',
            'BD' => 'BD',
            'United States' => 'US',
            'US' => 'US',
            'United Kingdom' => 'GB',
            'UK' => 'GB',
            'Country' => 'BD',
        ];

        return $countryMap[$countryName] ?? 'BD';
    }

    /**
     * Get all orders by user_id
     * 
     * @param Request $request
     * @param int|null $user_id
     * @return JsonResponse
     */
    public function getOrdersByCustomer(Request $request, $user_id = null): JsonResponse
    {
        try {
            $userId = $user_id ?? $request->input('user_id');
            
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User ID is required',
                ], 400);
            }

            if (!is_numeric($userId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'User ID must be a valid number',
                ], 400);
            }

            $userId = (int) $userId;

            $customer = Customer::where('user_id', $userId)->first();
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found with the provided user ID',
                    'user_id' => $userId,
                ], 404);
            }
            
            $orders = Order::where('customer_id', $customer->id)
                ->with([
                    'customer:id,name,phone,email',
                    'payment_method:id,name',
                    'staff_user:id,first_name,last_name',
                    'shop:id,name',
                    'order_details'
                ])
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 15));

            return response()->json([
                'success' => true,
                'message' => 'Orders retrieved successfully',
                'data' => OrderListResource::collection($orders),
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve orders: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get order details by invoice ID or order number
     * 
     * @param string $invoiceId
     * @return JsonResponse
     */
    public function getOrderByInvoice($invoiceId): JsonResponse
    {
        try {
            if (!$invoiceId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice ID or Order Number is required',
                ], 400);
            }

            $order = Order::where('order_number', $invoiceId)
                ->with([
                    'customer:id,name,phone,email',
                    'payment_method:id,name',
                    'staff_user:id,first_name,last_name',
                    'shop:id,name',
                    'order_details.brand:id,name',
                    'order_details.category:id,name',
                    'order_details.sub_category:id,name',
                    'order_details.supplier:id,name',
                    'transactions',
                    'transactions.customer',
                    'transactions.payment_method',
                ])
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found with the provided invoice ID or order number',
                    'invoice_id' => $invoiceId,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order details retrieved successfully',
                'data' => new OrderDetailsResource($order),
            ], 200);
        } catch (\Throwable $e) {
            info('GET_ORDER_BY_INVOICE_FAILED', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve order details: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get delivery status from Steadfast by consignment_id, invoice, or tracking_code
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getTrackingStatus(Request $request): JsonResponse
    {
        try {
            $steadfastService = new SteadfastService();
            $consignmentId = $request->input('consignment_id');
            $invoice = $request->input('invoice');
            $trackingCode = $request->input('tracking_code');

            $statusData = null;
            $identifierType = null;
            $identifierValue = null;

            if ($consignmentId) {
                $statusData = $steadfastService->getStatusByConsignmentId($consignmentId);
                $identifierType = 'consignment_id';
                $identifierValue = $consignmentId;
            } elseif ($invoice) {
                $statusData = $steadfastService->getStatusByInvoice($invoice);
                $identifierType = 'invoice';
                $identifierValue = $invoice;
            } elseif ($trackingCode) {
                $statusData = $steadfastService->getStatusByTrackingCode($trackingCode);
                $identifierType = 'tracking_code';
                $identifierValue = $trackingCode;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'At least one of consignment_id, invoice, or tracking_code is required',
                ], 400);
            }

            if (!$statusData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to retrieve delivery status from Steadfast API',
                    'identifier_type' => $identifierType,
                    'identifier_value' => $identifierValue,
                ], 404);
            }

            $order = null;
            if ($invoice) {
                $order = Order::where('order_number', $invoice)->first();
            } elseif ($trackingCode) {
                $order = Order::where('tracking_code', $trackingCode)->first();
            } elseif ($consignmentId) {
                $order = Order::where('consignment_id', $consignmentId)->first();
            }

            $orderData = null;
            if ($order) {
                $orderData = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'consignment_id' => $order->consignment_id,
                    'tracking_code' => $order->tracking_code,
                    'total' => $order->total,
                    'customer_id' => $order->customer_id,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Delivery status retrieved successfully',
                'data' => array_merge([
                    'identifier_type' => $identifierType,
                    'identifier_value' => $identifierValue,
                    'order' => $orderData,
                ], $statusData),
            ], 200);

        } catch (\Throwable $e) {
            info('TRACKING_STATUS_FAILED', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve tracking status: ' . $e->getMessage(),
            ], 500);
        }
    }
}
