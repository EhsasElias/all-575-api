<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBillingProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'buyer_type' => ['required', 'in:individual,business'],
            'company_name' => ['required_if:buyer_type,business', 'nullable', 'string', 'max:200'],
            'registration_number' => ['nullable', 'regex:/^[0-9]{10}$/'],
            'vat_number' => ['nullable', 'regex:/^3[0-9]{13}3$/'],
            'billing_address' => ['required_if:buyer_type,business', 'nullable', 'string', 'max:500'],
        ];
    }
}
