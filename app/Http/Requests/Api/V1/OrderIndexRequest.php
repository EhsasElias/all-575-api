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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
