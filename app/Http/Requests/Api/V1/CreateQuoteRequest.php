<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateQuoteRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'address_id' => ['required', 'integer'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
