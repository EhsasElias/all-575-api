<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCustomerCaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['support', 'return'])],
            'subject' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:3000'],
            'request_key' => ['required', 'uuid'],
            'order_item_id' => ['required_if:kind,return', 'prohibited_if:kind,support', 'integer', 'min:1'],
            'quantity' => ['required_if:kind,return', 'prohibited_if:kind,support', 'integer', 'min:1'],
        ];
    }
}
