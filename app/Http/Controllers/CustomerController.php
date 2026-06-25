<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOrder;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     * With page/per_page: returns paginated. Without: returns simple array (e.g. for dropdowns).
     */
    public function index(Request $request)
    {
        $search = trim((string) ($request->input('search') ?? ''));

        if (!$request->has('page') && !$request->has('per_page')) {
            $customers = (new Customer())->getCustomerBySearch($request->all());
            return response()->json($customers);
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = $perPage >= 1 && $perPage <= 100 ? $perPage : 15;

        $query = Customer::query()
            ->select('customers.id', 'customers.name', 'customers.phone', 'customers.email')
            ->selectRaw('(SELECT COUNT(*) FROM orders WHERE orders.customer_id = customers.id) as ecom_orders_count')
            ->selectRaw('(SELECT COUNT(*) FROM store_orders WHERE store_orders.customer_number = customers.phone) as store_orders_count');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('customers.name', 'like', '%' . $search . '%')
                    ->orWhere('customers.phone', 'like', '%' . $search . '%')
                    ->orWhere('customers.email', 'like', '%' . $search . '%');
            });
        }

        // has_orders=1 filters to customers with at least one order (default behaviour)
        if ($request->input('has_orders') === '1') {
            $query->where(function ($q) {
                $q->whereExists(fn ($s) => $s->from('orders')->whereColumn('orders.customer_id', 'customers.id'))
                  ->orWhereExists(fn ($s) => $s->from('store_orders')->whereColumn('store_orders.customer_number', 'customers.phone'));
            });
        }

        $query->orderBy('customers.id', 'desc');
        $paginated = $query->paginate($perPage);

        return response()->json($paginated);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCustomerRequest $request)
    {
        $customer =(new Customer())->store($request->all());
        return response()->json(['status' => 'success', 'message' => 'Customer added successfully']);
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Customer $customer)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Customer $customer)
    {
        //
    }

    /**
     * Get all orders (ecommerce + store) for a customer.
     */
    public function orders(Customer $customer)
    {
        $customer->loadMissing([]);

        $ecommerceOrders = Order::query()
            ->where('customer_id', $customer->id)
            ->with(['customer:id,name,phone', 'shop:id,name', 'payment_method:id,name'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn ($o) => [
                'id'             => $o->id,
                'order_number'   => $o->order_number ?? null,
                'total'          => $o->total ?? 0,
                'order_status'   => $o->order_status,
                'created_at'     => $o->created_at?->toIso8601String(),
                'shop'           => $o->shop ? ['id' => $o->shop->id, 'name' => $o->shop->name] : null,
                'payment_method' => $o->payment_method ? ['id' => $o->payment_method->id, 'name' => $o->payment_method->name] : null,
                'type'           => 'ecommerce',
            ]);

        $storeOrders = StoreOrder::query()
            ->where('customer_number', $customer->phone)
            ->with(['shop:id,name', 'paymentMethod:id,name'])
            ->orderBy('id', 'desc')
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'customer_number' => $o->customer_number,
                'total_amount' => $o->total_amount ?? 0,
                'status' => $o->status,
                'created_at' => $o->created_at?->toIso8601String(),
                'shop' => $o->shop ? ['id' => $o->shop->id, 'name' => $o->shop->name] : null,
                'payment_method' => $o->paymentMethod ? ['id' => $o->paymentMethod->id, 'name' => $o->paymentMethod->name] : null,
                'type' => 'store',
            ]);

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ],
            'ecommerce_orders' => $ecommerceOrders,
            'store_orders' => $storeOrders,
        ]);
    }
}
