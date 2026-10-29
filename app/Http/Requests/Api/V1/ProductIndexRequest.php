<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:100'],
            'seller' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            'zone_id' => ['nullable', 'integer', 'exists:service_zones,id'],
            'sort' => ['nullable', 'in:featured,newest,rating,best_selling,price_asc,price_desc'],
            'min_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'max_price' => ['nullable', 'integer', 'min:0', 'max:100000000', Rule::when($this->filled('min_price'), 'gte:min_price')],
            'in_stock' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
