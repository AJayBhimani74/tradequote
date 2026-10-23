<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RespondQuoteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vendor_note' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }
}
