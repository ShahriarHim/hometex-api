<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\TransferProduct;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates 30 demo products with realistic stock distribution across 3 shops,
 * then simulates all stock movement types so stock_ledger has rich data.
 *
 * Safe to re-run: uses a name prefix so old demo data is wiped first.
 */
class DemoProductSeeder extends Seeder
{
    private const PREFIX      = 'DEMO-';
    private const CATEGORY_ID = 12;  // Bedding
    private const SUB_CAT_ID  = 11;  // Bed Cover Set
    private const BRAND_ID    = 6;   // iPllow
    private const SUPPLIER_ID = 6;   // Test Supplier

    // Shop IDs that actually exist in the shops table
    private const SHOP_GULSHAN = 7;
    private const SHOP_B       = 15;
    private const SHOP_C       = 16;

    private array $products = [];

    public function run(): void
    {
        $this->clean();
        $this->createProducts();
        $this->assignToShops();
        $this->seedLedger();

        $this->command->info('');
        $this->command->info('DemoProductSeeder complete');
        $this->command->info('  Products created : ' . count($this->products));
        $this->command->info('  Ledger rows      : ' . StockLedger::whereIn('product_id', array_column($this->products, 'id'))->count());
    }

    // ─── 1. Wipe old demo data ─────────────────────────────────────────────────

    private function clean(): void
    {
        $ids = Product::where('name', 'like', self::PREFIX . '%')->pluck('id');
        if ($ids->isNotEmpty()) {
            StockLedger::whereIn('product_id', $ids)->delete();
            TransferProduct::whereIn('product_id', $ids)->delete();
            DB::table('shop_product')->whereIn('product_id', $ids)->delete();
            Product::whereIn('id', $ids)->delete();
            $this->command->info('Cleaned ' . $ids->count() . ' old demo products.');
        }
    }

    // ─── 2. Create 30 products ─────────────────────────────────────────────────

    private function createProducts(): void
    {
        $specs = [
            // [name-suffix,                        price, cost, disc%, stock]
            ['Premium Cotton Bedsheet King',         2800, 1600, 10, 120],
            ['Luxury Pillow Protector Set',          1200,  700,  5,  80],
            ['Microfibre Duvet Cover Double',        3500, 2000, 15,  60],
            ['Egyptian Cotton Towel Set',            1800, 1000, 10,  90],
            ['Bamboo Bed Sheet Single',              2200, 1300,  0,  45],
            ['Velvet Cushion Cover 4-Piece',          950,  500,  5,  30],
            ['Thermal Blanket King Size',            4200, 2500, 20, 100],
            ['Hotel Quality Bath Towel',             1100,  600,  0,  55],
            ['Memory Foam Pillow Standard',          3200, 1900, 10,  40],
            ['Quilted Mattress Protector',           1900, 1100,  5,  75],
            ['Blackout Curtain Pair 7ft',            5500, 3200, 15,  25],
            ['Waffle Weave Hand Towel',               650,  350,  0,  18],  // low stock
            ['Anti-Allergy Duvet Single',            2900, 1700, 10,  65],
            ['Linen Cushion Square 45cm',             850,  450,  0,  50],
            ['Percale Bed Sheet Queen',              2600, 1500,  8,  88],
            ['Plush Bathrobe Adults',                4800, 2800, 12,  35],
            ['Striped Beach Towel Large',            1500,  850,  5,  42],
            ['Jacquard Table Runner 2m',             1100,  620,  0,  20],  // low stock
            ['Reversible Quilt Cover King',          5200, 3000, 18,  55],
            ['Organic Cotton Face Towel',             480,  250,  0,   7],  // critical low
            ['Thermal Curtain Winter 8ft',           6200, 3600, 20,  15],  // low stock
            ['Kids Dino Print Bedsheet',             1800, 1000,  5,  60],
            ['Printed Cushion Cover Set 5',          1300,  750,  0,  33],
            ['Sateen Pillow Case Pair',               750,  400,  5,  95],
            ['Waterproof Mattress Cover',            2100, 1200,  0,   0],  // out of stock
            ['Seersucker Bedspread Double',          3100, 1800,  8,  48],
            ['Microfibre Bath Sheet XL',             1350,  780,  0,  22],
            ['Oxford Pillowcase Standard',            620,  320,  0, 110],
            ['Heavyweight Winter Blanket',           3800, 2200, 10,   5],  // critical low
            ['Embroidered Table Cloth 6-Seat',       2500, 1400, 12,  70],
        ];

        foreach ($specs as [$suffix, $price, $cost, $disc, $stock]) {
            $name = self::PREFIX . $suffix;
            $sku  = 'DEMO-' . strtoupper(Str::random(6));
            $slug = Str::slug($name) . '-' . Str::random(4);

            $this->products[] = Product::create([
                'name'                => $name,
                'slug'                => $slug,
                'sku'                 => $sku,
                'price'               => $price,
                'cost'                => $cost,
                'discount_percent'    => $disc,
                'discount_fixed'      => 0,
                'stock'               => $stock,
                'status'              => 1,
                'category_id'         => self::CATEGORY_ID,
                'sub_category_id'     => self::SUB_CAT_ID,
                'brand_id'            => self::BRAND_ID,
                'supplier_id'         => self::SUPPLIER_ID,
                'description'         => "Demo product: {$suffix}. Created by DemoProductSeeder.",
                'low_stock_threshold' => 10,
            ]);
        }

        $this->command->info('Created ' . count($this->products) . ' products.');
    }

    // ─── 3. Assign stock to shops ──────────────────────────────────────────────

    private function assignToShops(): void
    {
        foreach ($this->products as $product) {
            $total = (int) $product->stock;

            if ($total <= 0) {
                DB::table('shop_product')->insert([
                    'product_id' => $product->id, 'shop_id' => self::SHOP_GULSHAN,
                    'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
                continue;
            }

            // Distribute: 50% Gulshan, 30% B, 20% C
            $qGulshan = (int) round($total * 0.50);
            $qB       = (int) round($total * 0.30);
            $qC       = $total - $qGulshan - $qB;

            foreach ([self::SHOP_GULSHAN => $qGulshan, self::SHOP_B => $qB, self::SHOP_C => $qC] as $shopId => $qty) {
                if ($qty <= 0) continue;
                DB::table('shop_product')->insert([
                    'product_id' => $product->id, 'shop_id' => $shopId,
                    'quantity' => $qty, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    // ─── 4. Seed ledger with all movement types ────────────────────────────────

    private function seedLedger(): void
    {
        $now = Carbon::now();

        foreach ($this->products as $idx => $p) {
            $pid = $p->id;

            // Initial stock-in — 60 days ago
            $this->ledger($pid, self::SHOP_GULSHAN, $p->stock,             'manual', null, null, $now->copy()->subDays(60), 'Initial stock receipt from supplier');
            $this->ledger($pid, self::SHOP_B,       (int)($p->stock * 0.6),'manual', null, null, $now->copy()->subDays(60), 'Initial stock receipt from supplier');
            $this->ledger($pid, self::SHOP_C,       (int)($p->stock * 0.4),'manual', null, null, $now->copy()->subDays(60), 'Initial stock receipt from supplier');

            // Ecommerce orders — use SHOP_GULSHAN as the "online" stock pool (shop 4 doesn't exist in DB)
            $ecomCount = $this->ecomSalesCount($idx);
            for ($j = 0; $j < $ecomCount; $j++) {
                $this->ledger($pid, self::SHOP_GULSHAN, -rand(1, 3), 'ecommerce_order',
                    Order::class, 1000 + $idx * 10 + $j, $now->copy()->subDays(rand(1, 45)));
            }

            // POS orders (Gulshan)
            for ($j = 0, $max = rand(2, 8); $j < $max; $j++) {
                $this->ledger($pid, self::SHOP_GULSHAN, -rand(1, 2), 'pos_order',
                    Order::class, 2000 + $idx * 10 + $j, $now->copy()->subDays(rand(1, 30)));
            }

            // Store orders (Shop B)
            for ($j = 0, $max = rand(1, 5); $j < $max; $j++) {
                $this->ledger($pid, self::SHOP_B, -rand(1, 3), 'store_order',
                    'App\Models\StoreOrder', 3000 + $idx * 10 + $j, $now->copy()->subDays(rand(1, 20)));
            }

            // Transfers: every 6th product — Gulshan → Shop C
            if ($idx % 6 === 0 && $p->stock > 10) {
                $tQty = rand(2, 5);
                $tId  = TransferProduct::create([
                    'product_id'   => $pid,
                    'from_shop_id' => self::SHOP_GULSHAN,
                    'to_shop_id'   => self::SHOP_C,
                    'quantity'     => $tQty,
                    'status'       => 'approved',
                ])->id;
                $day = $now->copy()->subDays(rand(5, 15));
                $this->ledger($pid, self::SHOP_GULSHAN, -$tQty, 'transfer_out', TransferProduct::class, $tId, $day);
                $this->ledger($pid, self::SHOP_C,        $tQty, 'transfer_in',  TransferProduct::class, $tId, $day);
            }

            // Customer returns: every 7th product
            if ($idx % 7 === 0) {
                $this->ledger($pid, self::SHOP_GULSHAN, rand(1, 2), 'return',
                    Order::class, 5000 + $idx, $now->copy()->subDays(rand(1, 10)), 'Customer return — wrong size');
            }

            // Damage write-off: every 9th product
            if ($idx % 9 === 0) {
                $this->ledger($pid, self::SHOP_GULSHAN, -rand(1, 3), 'manual',
                    null, null, $now->copy()->subDays(rand(2, 8)), 'Damaged goods write-off');
            }

            // Cancelled order restore: every 13th product
            if ($idx % 13 === 0) {
                $this->ledger($pid, self::SHOP_B, rand(1, 2), 'restore',
                    'App\Models\StoreOrder', 6000 + $idx, $now->copy()->subDays(rand(1, 5)), 'Order cancelled: restored quantity');
            }
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function ledger(int $productId, int $shopId, int $change, string $type,
        ?string $refType, ?int $refId, Carbon $at, ?string $notes = null): void
    {
        StockLedger::create([
            'product_id'      => $productId,
            'shop_id'         => $shopId,
            'quantity_change' => $change,
            'type'            => $type,
            'reference_type'  => $refType,
            'reference_id'    => $refId,
            'created_by'      => null,
            'notes'           => $notes,
            'created_at'      => $at,
            'updated_at'      => $at,
        ]);
    }

    private function ecomSalesCount(int $idx): int
    {
        if (in_array($idx, [0, 2, 6]))        return rand(18, 25); // hot/trending
        if (in_array($idx, [1, 4, 8]))        return rand(12, 18); // good sellers
        if (in_array($idx, [11, 17, 24, 28])) return rand(0, 2);  // poor — discount candidates
        return rand(4, 12);
    }
}
