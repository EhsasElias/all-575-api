<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetryPaymentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['payment_method' => ['required', Rule::in(array_diff(config('store.payments.methods'), ['cash_on_delivery']))]];
    }

    public function method(): PaymentMethod
    {
        return PaymentMethod::from($this->string('payment_method')->toString());
    }
}
