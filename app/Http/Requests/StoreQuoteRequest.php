<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreQuoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'buyer_note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ids = collect($this->input('items'))->pluck('product_id');
            $valid = Product::where('vendor_id', $this->input('vendor_id'))
                ->where('is_active', true)
                ->whereIn('id', $ids)
                ->count();

            if ($valid !== $ids->count()) {
                $validator->errors()->add('items', 'Every item must be an active product of the selected vendor.');
            }
        }];
    }
}
