<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderHistory extends Model
{
    protected $table = 'order_history';

    protected $guarded = [];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    public const ACTION_ADDRESS_UPDATED = 'address_updated';
    public const ACTION_ITEM_ADDED = 'item_added';
    public const ACTION_ITEM_REMOVED = 'item_removed';
    public const ACTION_ITEM_UPDATED = 'item_updated';
    public const ACTION_ORDER_CANCELLED = 'order_cancelled';
    public const ACTION_PAYMENT_UPDATED = 'payment_updated';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
