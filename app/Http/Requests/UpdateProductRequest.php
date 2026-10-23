<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sku' => [
                'sometimes', 'string', 'max:64',
                Rule::unique('products')
                    ->where('vendor_id', $this->route('product')->vendor_id)
                    ->ignore($this->route('product')),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
