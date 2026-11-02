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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        if (! $placed->replayed) {
            try {
                DB::table('customer_notifications')->insert([
                'user_id' => $request->user()->id,
                'type' => 'order_placed',
                'title' => 'تم استلام طلبك',
                'message' => 'تم تسجيل طلبك بنجاح. يمكنك متابعة حالته من صفحة طلباتي.',
                'target_path' => '/orders',
                'created_at' => now(),
                'updated_at' => now(),
                ]);
            } catch (Throwable $exception) {
                Log::warning('Customer notification could not be recorded', [
                    'order_id' => $placed->order->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json(['data' => [
            'order' => (new OrderDetailResource($orders->load($placed->order)))->resolve($request),
            'payment' => $placed->payment === null ? null : (new PaymentResource($placed->payment, $placed->session))->resolve($request),
            'replayed' => $placed->replayed,
        ]], $placed->replayed ? 200 : 201);
    }
}
