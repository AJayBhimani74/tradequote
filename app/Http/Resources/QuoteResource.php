<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'buyer' => $this->whenLoaded('buyer', fn () => ['id' => $this->buyer->id, 'name' => $this->buyer->name]),
            'vendor' => $this->whenLoaded('vendor', fn () => [
                'id' => $this->vendor->id,
                'company_name' => $this->vendor->company_name,
            ]),
            'buyer_note' => $this->buyer_note,
            'vendor_note' => $this->vendor_note,
            'total' => $this->total,
            'valid_until' => $this->valid_until?->toDateString(),
            'responded_at' => $this->responded_at,
            'created_at' => $this->created_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'sku' => $i->sku,
                'name' => $i->name,
                'unit' => $i->unit,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
            ])),
        ];
    }
}
