<?php

namespace App\Manager;

use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderManager{

    private const ORDER_PREFIX = 'HTB';

    /** Shop ID used for ecommerce/online orders (shop_quantity reduced here when online order placed) */
    public const ECOMMERCE_SHOP_ID = 4;


    /**
     * @param int $shop_id
     * @return string
     * @throws Exception
     */
    public static function generateOrderNumber(int $shop_id):string
    {
        return self::ORDER_PREFIX.$shop_id.Carbon::now()->format('dmy').random_int(1000, 9999);
    }

    public static function handle_order_data(array $input)
    {
        $sub_total = 0;
        $discount = 0;
        $total = 0;
        $quantity = 0;
        $order_details = [];
        if (isset($input['carts'])) {
            foreach ($input['carts'] as $key => $cart) {
                // lockForUpdate prevents concurrent orders from double-decrementing the same stock row
                $product = Product::lockForUpdate()->find((int) $key);
                if (!$product || $product->stock < $cart['quantity']) {
                    $name = $product->name ?? "Product #{$key}";
                    info('PRODUCT_STOCK_OUT', ['product' => $product, 'cart' => $cart]);
                    return ['error_description' => $name . ' is out of stock'];
                }
                $price = PriceManager::calculate_sell_price(
                    $product->price, $product->discount_percent, $product->discount_fixed,
                    $product->discount_start, $product->discount_end
                );
                $discount  += $price['discount'] * $cart['quantity'];
                $quantity  += $cart['quantity'];
                $sub_total += $product->price * $cart['quantity'];
                $total     += $price['price'] * $cart['quantity'];

                $product->decrement('stock', $cart['quantity']);
                $product->quantity = $cart['quantity'];
                $order_details[] = $product;
            }
        }
        return [
            'sub_total'     => $sub_total,
            'discount'      => $discount,
            'total'         => $total,
            'quantity'      => $quantity,
            'order_details' => $order_details,
        ];
    }

    public static function decidePaymentStatus(int $amount, int $paid_amount)
    {
        /**
         * 1= paid
         * 2= partially paid
         * 3= unpaid
         */
        $payment_status = 3;
        if($amount <= $paid_amount){
            $payment_status = 1;
        }elseif($paid_amount <= 0){
            $payment_status = 3;
        }else{
            $payment_status = 2;
        }
        return $payment_status;
    }
    /**
     * Decrement shop_product.quantity for the given shop and product.
     * Used when ecommerce order or store order reduces stock so shop_quantity stays in sync.
     */
    public static function decrementShopProductStock(int $shopId, int $productId, int $quantity): void
    {
        DB::table('shop_product')
            ->where('shop_id', $shopId)
            ->where('product_id', $productId)
            ->decrement('quantity', $quantity);
    }

}
