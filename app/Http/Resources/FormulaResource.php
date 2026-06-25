<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FormulaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'formula'     => $this->formula,
            'field_limit' => $this->field_limit,
            'description' => $this->description,
            'status'      => (int) $this->status,
            'created_by'  => $this->user?->name,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
