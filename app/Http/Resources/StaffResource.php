<?php

namespace App\Http\Resources;

use App\Manager\ImageUploadManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'first_name'         => $this->first_name,
            'last_name'          => $this->last_name,
            'name'               => $this->name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'phone_country_code' => $this->phone_country_code ?? '+880',
            'date_of_birth'      => $this->date_of_birth?->format('Y-m-d'),
            'gender'             => $this->gender,
            'user_type'          => $this->user_type,
            'employee_type'      => $this->employee_type,
            'role'               => $this->roles->first()?->name,
            'roles'              => $this->roles->pluck('name'),
            'staff_shop_id'      => $this->staff_shop_id,
            'status'             => $this->status,
            'bio'                => $this->bio,
            'nid'                => $this->nid,
            'avatar'             => $this->avatar,
            'avatar_url'         => ImageUploadManager::url($this->avatar),
            'shop'               => $this->whenLoaded('staffShop', fn () => [
                'id'   => $this->staffShop->id,
                'name' => $this->staffShop->name,
            ]),
            'last_login_at'      => $this->last_login_at?->toISOString(),
            'created_at'         => $this->created_at->toISOString(),
        ];
    }
}
