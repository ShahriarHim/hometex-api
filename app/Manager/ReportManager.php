<?php
namespace App\Manager;

use App\Models\Order;
use App\Models\Product;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportManager
{

    public int $total_product = 0;

    public const LOW_STOCK_ALERT = 5;
    public int $total_stock = 0;
    public int $low_stock = 0;
    public int $buying_stock_price = 0;
    public int $saleing_stock_price = 0;
    public int $possible_profit = 0;
    public int $total_sale = 0;
    public int $total_sale_today = 0;
    public int $total_purchase = 0;
    public int $total_purchase_today = 0;
    private bool $is_admin = false;
    private bool $sales_admin_id;
    private Collection $products;
    private Collection $orders;
    /** @var array<int,int> product_id => total quantity across shops */
    private array $productStockByShop = [];

    function  __construct($auth)
    {
        $user = $auth->user('sanctum') ?? $auth->user();

        if ($user?->hasRole('admin')) {
            $this->is_admin = true;
        }

        if ($user) {
            $this->sales_admin_id = $user->id;
        } else {
            $this->sales_admin_id = 0;
        }

        $this->getProducts();
        $this->loadProductStockFromShops();
        $this->getOrders();
        $this->setTotalProduct();
        $this->calculateStock();
        $this->findLowStock();
        $this->calculateBuyingStockPrice();
        $this->calculateSaleingStockPrice();
        $this->calculatePossibleProfit();
        $this->calculateTotalSale();
        $this->calculateTotalSaleToday();
        $this->calculateTotalPurchase();
        $this->calculatePurchaseToday();
    }

    private function getProducts()
    {
        $this->products = (new Product)->getAllProduct();
    }

    private function loadProductStockFromShops(): void
    {
        $rows = DB::table('shop_product')
            ->select('product_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id')
            ->get();
        foreach ($rows as $row) {
            $this->productStockByShop[(int) $row->product_id] = (int) $row->total;
        }
    }

    private function setTotalProduct()
    {
        $this->total_product = count($this->products);
    }

    private function calculateStock()
    {
        $this->total_stock = (int) array_sum($this->productStockByShop);
    }

    private function findLowStock()
    {
        $threshold = SystemSetting::get('low_stock_threshold', self::LOW_STOCK_ALERT);
        $this->low_stock = 0;
        foreach ($this->productStockByShop as $productId => $qty) {
            if ($qty <= $threshold) {
                $this->low_stock++;
            }
        }
    }

    private function getShopQuantityForProduct($productId): int
    {
        return $this->productStockByShop[$productId] ?? 0;
    }

    private function calculateBuyingStockPrice()
    {
        foreach ($this->products as $product) {
            $qty = $this->getShopQuantityForProduct((int) $product->id);
            $this->buying_stock_price += ($product->cost ?? 0) * $qty;
        }
    }

    private function calculateSaleingStockPrice()
    {
        foreach ($this->products as $product) {
            $qty = $this->getShopQuantityForProduct((int) $product->id);
            $this->saleing_stock_price += ($product->price ?? 0) * $qty;
        }
    }

    private function calculateTotalPurchase()
    {
        $this->total_purchase = $this->buying_stock_price;
    }

    private function calculatePurchaseToday()
    {
        $product_buy_today = $this->products->whereBetween('created_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()]);
        foreach ($product_buy_today as $product) {
            $qty = $this->getShopQuantityForProduct((int) $product->id);
            $this->total_purchase_today += ($product->cost ?? 0) * $qty;
        }
    }

    private function calculatePossibleProfit()
    {
        $this->possible_profit = $this->saleing_stock_price - $this->buying_stock_price;
    }

    private function getOrders()
    {
        $this->orders = (new Order())->getAllOrdersForReport($this->is_admin, $this->sales_admin_id);
    }

    private function calculateTotalSale()
    {
        $this->total_sale = $this->orders->sum('total');
    }

    private function calculateTotalSaleToday()
    {
        $this->total_sale_today = $this->orders->whereBetween('created_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()])->sum('total');
    }
}

