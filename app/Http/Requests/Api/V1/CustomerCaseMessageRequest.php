<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CustomerCaseMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return ['message' => ['required', 'string', 'max:3000']];
    }
}
