<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment_status = 'Unpaid';
        if ($this->payment_status === Order::PAID) {
            $payment_status = 'Paid';
        } elseif ($this->payment_status === Order::PARTIAL_PAID) {
            $payment_status = 'Partially Paid';
        } elseif ($this->payment_status === null || $this->payment_status === '' || $this->payment_status === 0) {
            $payment_status = 'Unpaid';
        }
        $order_status_string = 'Pending';
        if ($this->order_status == Order::STATUS_COMPLETED) {
            $order_status_string = 'Completed';
        } elseif ($this->order_status == Order::STATUS_PROCESSED) {
            $order_status_string = 'Processed';
        } elseif ($this->order_status == Order::STATUS_CANCELLED) {
            $order_status_string = 'Cancelled';
        }
        return [
            'id'=>$this->id,
            'created_at'=>$this->created_at->toDayDateTimeString(),
            'updated_at'=> $this->created_at != $this->updated_at ? $this->updated_at->toDayDateTimeString(): 'Not Updated',
            'customer_name'=>$this->customer?->name,
            'customer_phone'=>$this->customer?->phone,
            'order_number'=>$this->order_number,
            'order_status'=>$this->order_status,
            'order_status_string'=>$order_status_string,
            'payment_method'=>$this->payment_method?->name,
            'payment_status'=>$payment_status,
            'staff_user' => $this->staff_user ? trim($this->staff_user->first_name . ' ' . $this->staff_user->last_name) : null,
            'shop'=>$this->shop?->name,
            'discount'=>$this->discount,
            'due_amount'=>$this->due_amount,
            'paid_amount'=>$this->paid_amount,
            'quantity'=>$this->quantity,
            'sub_total'=>$this->sub_total,
            'total'=>$this->total,
            'stock_adjusted_at' => $this->stock_adjusted_at?->toIso8601String(),
            'needs_adjustment' => $this->stock_adjusted_at === null,
        ];
    }
}
