<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CustomerRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'kind' => ['required', 'in:bulk_quote,data_access,account_closure'],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }
}
