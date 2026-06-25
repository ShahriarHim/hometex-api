<?php

namespace App\Http\Resources;

use App\Manager\ImageUploadManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShopEditResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'=>$this->id,
            'details'=>$this->details,
            'email'=>$this->email,
            'name'=>$this->name,
            'phone'=>$this->phone,
            'logo' => ImageUploadManager::url($this->logo),
            'status'=>$this->status,
            'address'=>$this->address?->address,
            'landmark'=>$this->address?->landmark,
            'division_id'=>$this->address?->division_id,
            'district_id'=>$this->address?->district_id,
            'area_id'=>$this->address?->area_id,
            'division_name'=>$this->address?->division?->name,
            'district_name'=>$this->address?->district?->name,
            'area_name'=>$this->address?->area?->name,
        ];
    }
}
