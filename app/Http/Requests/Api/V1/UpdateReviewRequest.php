<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReviewRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'body' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
