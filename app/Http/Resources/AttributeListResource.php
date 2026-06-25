<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttributeListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'status'     => (int) $this->status,
            'created_by' => $this->user?->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'value'      => ValueListResource::collection($this->value),
        ];
    }
}
