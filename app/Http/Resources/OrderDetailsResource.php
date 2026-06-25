<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payment_status = 'Unpaid';
        if($this->payment_status === Order::PAID){
            $payment_status = 'Paid';
        }elseif ($this->payment_status === Order::PARTIAL_PAID){
            $payment_status = 'Partially Paid';
        }elseif ($this->payment_status === null || $this->payment_status === '' || $this->payment_status === 0){
            $payment_status = 'Unpaid';
        }
        $orderStatusString = 'Pending';
        if ($this->order_status == Order::STATUS_COMPLETED) {
            $orderStatusString = 'Completed';
        } elseif ($this->order_status == Order::STATUS_CANCELLED) {
            $orderStatusString = 'Cancelled';
        } elseif ($this->order_status == Order::STATUS_PROCESSED) {
            $orderStatusString = 'Processed';
        }
        return [
            'id'=>$this->id,
            'created_at'=>$this->created_at ? $this->created_at->toDayDateTimeString() : '',
            'updated_at'=> $this->updated_at && $this->created_at != $this->updated_at ? $this->updated_at->toDayDateTimeString(): 'Not Updated',
            'customer' => $this->when($this->customer, fn () => new CustomerDetailsResource($this->customer)),
            'order_number'=>$this->order_number,
            'order_status'=>$this->order_status,
            'order_status_string'=>$orderStatusString,
            'shipping_name'=>$this->shipping_name,
            'shipping_phone'=>$this->shipping_phone,
            'shipping_email'=>$this->shipping_email,
            'shipping_address_line_1'=>$this->shipping_address_line_1,
            'shipping_address_line_2'=>$this->shipping_address_line_2,
            'shipping_city'=>$this->shipping_city,
            'shipping_state'=>$this->shipping_state,
            'shipping_postal_code'=>$this->shipping_postal_code,
            'shipping_country'=>$this->shipping_country,
            'billing_name'=>$this->billing_name,
            'billing_phone'=>$this->billing_phone,
            'billing_email'=>$this->billing_email,
            'billing_address_line_1'=>$this->billing_address_line_1,
            'billing_address_line_2'=>$this->billing_address_line_2,
            'billing_city'=>$this->billing_city,
            'billing_state'=>$this->billing_state,
            'billing_postal_code'=>$this->billing_postal_code,
            'billing_country'=>$this->billing_country,
            'payment_method'=>new PaymentMethodDetailsResource($this->payment_method),
            'payment_status'=>$payment_status,
            'staff_user' => $this->staff_user ? [
                'id' => $this->staff_user->id,
                'name' => trim($this->staff_user->first_name . ' ' . $this->staff_user->last_name),
            ] : null,
            'shop'=>new ShopListResource($this->shop),
            'discount'=>$this->discount,
            'due_amount'=>$this->due_amount,
            'paid_amount'=>$this->paid_amount,
            'quantity'=>$this->quantity,
            'sub_total'=>$this->sub_total,
            'total'=>$this->total,
            'order_details'=> OrderDetailsListResource::collection($this->order_details),
            'transactions'=> TransactionListResource::collection($this->transactions),
            'stock_adjusted_at' => $this->stock_adjusted_at?->toIso8601String(),
            'needs_adjustment' => $this->stock_adjusted_at === null,
            'history' => OrderHistoryResource::collection($this->whenLoaded('history')),
        ];
    }
}
