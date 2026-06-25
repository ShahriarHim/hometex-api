<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLedger extends Model
{
    protected $table = 'stock_ledger';

    protected $fillable = [
        'shop_id',
        'product_id',
        'quantity_change',
        'unit_price',
        'type',
        'reference_type',
        'reference_id',
        'created_by',
        'notes',
    ];

    // Movement types — covers every way stock moves in the system
    public const TYPE_ECOMMERCE_ORDER = 'ecommerce_order';  // online order via ECOM
    public const TYPE_STORE_ORDER     = 'store_order';       // in-store order via IMS store orders
    public const TYPE_POS_ORDER       = 'pos_order';         // POS new-sale via IMS
    public const TYPE_MANUAL          = 'manual';            // manual stock adjustment
    public const TYPE_RESTORE         = 'restore';           // stock restored on order cancel/edit
    public const TYPE_RETURN          = 'return';            // customer return
    public const TYPE_TRANSFER_IN     = 'transfer_in';       // received from another shop
    public const TYPE_TRANSFER_OUT    = 'transfer_out';      // sent to another shop

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
