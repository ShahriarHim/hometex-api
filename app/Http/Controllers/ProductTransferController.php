<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Models\StockLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\TransferProduct;
use App\Models\ShopProduct;


class ProductTransferController extends Controller
{
    /**
     * Create a new product transfer and execute stock movement immediately.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'product_id' => 'required|integer',
            'from_shop_id' => 'required|integer',
            'to_shop_id' => 'required|integer',
            'attribute_id' => 'nullable|integer',
            'quantity' => 'required|integer|min:1',
        ]);

        $assignedShopId = $request->user('sanctum')?->assignedShopId();
        if ($assignedShopId && (int) $validatedData['from_shop_id'] !== $assignedShopId) {
            return response()->json(['status' => 'error', 'message' => 'You can only transfer stock from your assigned branch.'], 403);
        }

        $fromShopProduct = ShopProduct::where('product_id', $validatedData['product_id'])
            ->where('shop_id', $validatedData['from_shop_id'])
            ->first();

        if (!$fromShopProduct) {
            return response()->json([
                'status' => 'error',
                'message' => 'Product is not assigned to the source shop.',
            ], 404);
        }

        $available = (int) $fromShopProduct->quantity;
        if ($available < $validatedData['quantity']) {
            return response()->json([
                'status' => 'error',
                'message' => "Insufficient quantity in source shop. Available: {$available}.",
            ], 400);
        }

        try {
            $transfer = TransferProduct::create(array_merge($validatedData, ['status' => 'pending']));
            ActivityLogService::transferCreated($transfer->id);
            return response()->json(['status' => 'success', 'message' => 'Transfer request submitted and awaiting approval', 'data' => $transfer], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transfer failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Move quantity from from_shop to to_shop in shop_product.
     */
    private function executeStockMovement(TransferProduct $transfer): void
    {
        $product = $transfer->product;
        $fromShop = $transfer->fromShop;
        $toShop = $transfer->toShop;

        $fromShopProduct = ShopProduct::where('product_id', $product->id)
            ->where('shop_id', $fromShop->id)
            ->first();

        if ($fromShopProduct) {
            $fromShopProduct->decrement('quantity', $transfer->quantity);
        }

        $toShopProduct = ShopProduct::where('product_id', $product->id)
            ->where('shop_id', $toShop->id)
            ->first();

        if ($toShopProduct) {
            $toShopProduct->increment('quantity', $transfer->quantity);
        } else {
            ShopProduct::create([
                'product_id' => $product->id,
                'shop_id' => $toShop->id,
                'quantity' => $transfer->quantity,
            ]);
        }
    }

    /**
     * List all product transfers.
     */
    public function index(Request $request)
    {
        $query = TransferProduct::with('product', 'fromShop', 'toShop', 'attribute')
            ->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Branch-scoped: staff can only see transfers involving their assigned shop
        $assignedShopId = $request->user('sanctum')?->assignedShopId();
        if ($assignedShopId) {
            $query->where(function ($q) use ($assignedShopId) {
                $q->where('from_shop_id', $assignedShopId)
                  ->orWhere('to_shop_id', $assignedShopId);
            });
        }

        $perPage = min((int) ($request->per_page ?? 15), 100);
        $transfers = $query->paginate($perPage);

        return response()->json([
            'data' => $transfers->items(),
            'meta' => [
                'total'        => $transfers->total(),
                'per_page'     => $transfers->perPage(),
                'current_page' => $transfers->currentPage(),
                'last_page'    => $transfers->lastPage(),
                'from'         => $transfers->firstItem(),
            ],
        ]);
    }

    /**
     * Show the details of a specific product transfer.
     */
    public function show(TransferProduct $transfer)
    {
        $data = [
            'id' => $transfer->id,
            'product_id' => $transfer->product_id,
            'product_name' => $transfer->product_name, // Get the product name via accessor
            'from_shop_id' => $transfer->from_shop_id,
            'from_shop_name' => $transfer->from_shop_name, // Get the from_shop name via accessor
            'to_shop_id' => $transfer->to_shop_id,
            'to_shop_name' => $transfer->to_shop_name, // Get the to_shop name via accessor
            'quantity' => $transfer->quantity,
            'status' => $transfer->status,
        ];

        return response()->json(['data' => $data]);
    }


    /**
     * Approve a product transfer (executes stock movement if still pending).
     */
    public function approve(TransferProduct $transfer)
    {
        if ($transfer->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Transfer is already ' . $transfer->status], 400);
        }

        try {
            DB::beginTransaction();
            $transfer->update(['status' => 'approved']);
            $this->executeStockMovement($transfer);

            StockLedger::create([
                'shop_id'         => $transfer->from_shop_id,
                'product_id'      => $transfer->product_id,
                'quantity_change' => -(int) $transfer->quantity,
                'type'            => StockLedger::TYPE_TRANSFER_OUT,
                'reference_type'  => TransferProduct::class,
                'reference_id'    => $transfer->id,
                'created_by'      => request()->user()?->id,
            ]);
            StockLedger::create([
                'shop_id'         => $transfer->to_shop_id,
                'product_id'      => $transfer->product_id,
                'quantity_change' => (int) $transfer->quantity,
                'type'            => StockLedger::TYPE_TRANSFER_IN,
                'reference_type'  => TransferProduct::class,
                'reference_id'    => $transfer->id,
                'created_by'      => request()->user()?->id,
            ]);

            DB::commit();
            ActivityLogService::transferApproved($transfer->id);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Approval failed: ' . $e->getMessage()], 500);
        }

        return response()->json(['status' => 'success', 'message' => 'Product transfer approved successfully']);
    }

    /**
     * Reject a product transfer.
     */
    public function reject(TransferProduct $transfer)
    {
        if ($transfer->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'Transfer is already ' . $transfer->status], 400);
        }

        $transfer->update(['status' => 'rejected']);
        ActivityLogService::transferRejected($transfer->id);

        return response()->json(['status' => 'success', 'message' => 'Product transfer rejected']);
    }
}
