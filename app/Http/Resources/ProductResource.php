<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'price' => $this->price,
            'stock' => $this->stock,
            'is_active' => $this->is_active,
            'vendor' => [
                'id' => $this->vendor_id,
                'company_name' => $this->whenLoaded('vendor', fn () => $this->vendor->company_name),
            ],
        ];
    }
}
