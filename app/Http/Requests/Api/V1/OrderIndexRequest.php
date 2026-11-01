<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:pending_payment,confirmed,completed,cancelled'],
            'q' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('from'), 'after_or_equal:from')],
            'product' => ['nullable', 'string', 'max:120'],
            'seller' => ['nullable', 'string', 'max:120'],
            'branch' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['nullable', 'in:mada,card,apple_pay,cash_on_delivery,none'],
            'min_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:999999999', Rule::when($this->filled('min_price'), 'gte:min_price')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
