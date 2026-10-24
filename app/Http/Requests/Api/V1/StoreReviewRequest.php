<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_slug' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
