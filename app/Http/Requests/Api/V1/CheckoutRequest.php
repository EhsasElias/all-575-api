<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Sent by the app for each checkout attempt, so a retried request can never create a second order.
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'quote_id' => ['required', 'string', 'size:26'],
            'slot_id' => ['required', 'integer'],
            'payment_method' => ['required', Rule::in(config('store.payments.methods'))],
            'purchase_order_reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function method(): PaymentMethod
    {
        return PaymentMethod::from($this->string('payment_method')->toString());
    }
}
