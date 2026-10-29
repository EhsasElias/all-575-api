<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\Actions\PlaceOrder;
use App\Domain\Orders\Actions\PlaceOrderCommand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckoutRequest;
use App\Http\Resources\OrderDetailResource;
use App\Http\Resources\PaymentResource;
use App\Http\Support\OrderPresenter;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    /**
     * Creates the unpaid order and the payment attempt. The order is only confirmed later, by the provider's callback.
     * Repeating the request with the same Idempotency-Key returns the same order instead of creating another.
     *
     * @status 201
     *
     * @response array{data: array{order: OrderDetailResource, payment: ?PaymentResource, replayed: bool}}
     */
    public function store(CheckoutRequest $request, PlaceOrder $placeOrder, OrderPresenter $orders): JsonResponse
    {
        $placed = $placeOrder->handle(new PlaceOrderCommand(
            $request->user(),
            $request->string('quote_id')->toString(),
            $request->integer('slot_id'),
            $request->method(),
            $request->string('idempotency_key')->toString(),
            $request->input('notes'),
            $request->input('purchase_order_reference'),
        ));

        return response()->json(['data' => [
            'order' => (new OrderDetailResource($orders->load($placed->order)))->resolve($request),
            'payment' => $placed->payment === null ? null : (new PaymentResource($placed->payment, $placed->session))->resolve($request),
            'replayed' => $placed->replayed,
        ]], $placed->replayed ? 200 : 201);
    }
}
