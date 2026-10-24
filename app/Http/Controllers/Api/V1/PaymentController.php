<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\Actions\RetryPayment;
use App\Domain\Payments\Models\Payment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RetryPaymentRequest;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /** The app polls this after the customer returns from the provider. "Paid" here comes only from a verified callback. */
    public function show(Request $request, string $payment): PaymentResource
    {
        return new PaymentResource(
            Payment::query()->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id))->findOrFail($payment),
        );
    }

    public function retry(RetryPaymentRequest $request, string $order, RetryPayment $retry): JsonResponse
    {
        $owned = $request->user()->orders()->findOrFail($order);

        [$payment, $session] = $retry->handle($owned, $request->method());

        return (new PaymentResource($payment, $session))->response()->setStatusCode(201);
    }
}
