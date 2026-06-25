<?php

namespace App\Models;

use App\Manager\OrderManager;
use App\Models\StockLedger;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id', 'staff_user_id', 'shop_id',
        'sub_total', 'discount', 'total', 'quantity',
        'paid_amount', 'due_amount', 'due_date',
        'order_status', 'order_number',
        'payment_method_id', 'payment_status', 'shipment_status',
        'is_guest_order', 'guest_token', 'ip_address', 'user_agent',
        'note', 'stock_adjusted_at',
    ];
    
    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_guest_order' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'stock_adjusted_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for arrays/JSON.
     */
    protected $hidden = [
        'guest_token', // Hide token in responses for security
        'ip_address',
        'user_agent',
    ];

    public const STATUS_PENDING = 1;
    public const STATUS_PROCESSED = 2;
    public const STATUS_COMPLETED = 3;
    public const STATUS_CANCELLED = 4;
    public const SHIPMENT_STATUS_COMPLETED = 1;
    public const PAID = 1;
    public const PARTIAL_PAID = 2;
    public const UNPAID = 3;


    public function getAllOrders(array $input, $auth)
    {
        $is_admin = $auth->guard('sanctum')->check();
        $query = self::query();
        $query->with(
            [
                'customer:id,name,phone',
                'payment_method:id,name',
                'staff_user:id,first_name,last_name',
                'shop:id,name',
                ]
        );
        if (!empty($input['search'])) {
            $search = trim((string) $input['search']);
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('shop', function ($sq) use ($search) {
                        $sq->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('staff_user', function ($sq) use ($search) {
                        $sq->where('first_name', 'like', '%' . $search . '%')
                           ->orWhere('last_name', 'like', '%' . $search . '%');
                    });
            });
        }
        if (!empty($input['order_by'])) {
            $direction = !empty($input['direction']) && strtolower($input['direction']) === 'asc' ? 'asc' : 'desc';
            $allowed = ['id', 'order_number', 'created_at', 'updated_at'];
            $orderBy = in_array($input['order_by'], $allowed, true) ? $input['order_by'] : 'id';
            $query->orderBy($orderBy, $direction);
        }
        if (!$is_admin) {
            $user = $auth->user();
            if ($user instanceof \App\Models\User) {
                $assignedShopId = $user->assignedShopId();
                if ($assignedShopId) {
                    $query->where('shop_id', $assignedShopId);
                }
            }
        }
        $perPage = isset($input['per_page']) && (int) $input['per_page'] > 0 ? (int) $input['per_page'] : 10;
        return $query->paginate($perPage);
    }

    /**
     * @param array $input
     * @param $auth
     * @return array|string[]|null
     */
    public function placeOrder(array $input, $auth)
    {
        $order_data = $this->prepareData($input, $auth);
        if (isset($order_data['error_description'])) {
            return $order_data;
        }

        $order = DB::transaction(function () use ($order_data, $input, $auth) {
            $order  = self::query()->create($order_data['order_data']);
            (new OrderDetails())->storeOrderDetails($order_data['order_details'], $order);
            (new Transaction())->storeTransaction($input, $order, $auth);

            $shopId = $order_data['order_data']['shop_id'];
            if ($shopId) {
                foreach ($order_data['order_details'] as $product) {
                    OrderManager::decrementShopProductStock($shopId, $product->id, $product->quantity);
                    StockLedger::create([
                        'shop_id'         => $shopId,
                        'product_id'      => $product->id,
                        'quantity_change' => -$product->quantity,
                        'unit_price'      => $product->price,
                        'type'            => StockLedger::TYPE_POS_ORDER,
                        'reference_type'  => Order::class,
                        'reference_id'    => $order->id,
                        'created_by'      => $auth->id,
                    ]);
                }
            }

            return $order;
        });

        return $order;
    }

    /**
     * @param array $input
     * @param $auth
     * @return array|string[]
     */
    private function prepareData(array $input, $auth)
    {
       $price = OrderManager::handle_order_data($input);
       if(isset($price['error_description'])){
        return $price;
       }else{

           if ($auth instanceof \App\Models\User) {
               $primaryShop = $auth->primaryShop();
               $shopId = $primaryShop ? $primaryShop->id : null;
           } else {
               $shopId = null;
           }

           $paid_amount = (int) ($input['orderSummary']['paid_amount'] ?? 0);
           $total = (int) $price['total'];
           $due_amount = $total - $paid_amount;
           $order_data = [
               'customer_id' =>$input['orderSummary']['customer_id'],
               'staff_user_id'=> $auth->id,
               'shop_id' => $shopId,
               'sub_total' => $price['sub_total'],
               'discount' => $price['discount'],
               'total' => $total,
               'quantity' => $price['quantity'],
               'paid_amount' => $paid_amount,
               'due_amount' => $due_amount,
               'order_status' => self::STATUS_COMPLETED,
               'order_number' => OrderManager::generateOrderNumber($shopId),
               'payment_method_id'=> $input['orderSummary']['payment_method_id'],
               'payment_status'=> OrderManager::decidePaymentStatus($total, $paid_amount),
               'shipment_status' => self::SHIPMENT_STATUS_COMPLETED,
       ];
       return ['order_data'=>$order_data, 'order_details'=>$price['order_details']];
       }
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
    public function payment_method()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
    public function staff_user()
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
    public function order_details()
    {
        return $this->hasMany(OrderDetails::class);
    }
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function history()
    {
        return $this->hasMany(OrderHistory::class)->orderByDesc('created_at');
    }
    public function getAllOrdersForReport(bool $is_admin, int $staff_user_id, array $columns = ['*'])
    {
        $query = DB::table('orders')->select($columns);
        if (!$is_admin) {
            $query->where('staff_user_id', $staff_user_id);
        }
        return collect($query->get());
    }

}
