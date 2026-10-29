<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Services\PaymentProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Local and testing only (the route is not registered anywhere else): plays the provider's part for a payment. */
class DevPaymentController extends Controller
{
    public function simulate(Request $request, string $payment, PaymentGateway $gateway, PaymentProcessor $processor): JsonResponse
    {
        abort_unless($gateway instanceof FakeGateway, 404);

        $request->validate(['outcome' => ['required', 'in:succeed,fail']]);

        $owned = Payment::query()->whereHas('order', fn ($query) => $query->where('user_id', $request->user()->id))->findOrFail($payment);
        abort_unless($owned->provider === $gateway->name(), 404);
        $succeed = $request->input('outcome') === 'succeed';

        $callback = $gateway->signedCallback([
            'id' => 'evt_'.Str::lower((string) Str::ulid()),
            'type' => $succeed ? 'charge.succeeded' : 'charge.failed',
            'reference' => $owned->provider_reference,
            'amount' => $owned->amount_halalas,
            'failure_code' => $succeed ? null : 'card_declined',
        ]);

        $event = $gateway->parseWebhook($callback['body'], array_map(fn (string $v): array => [$v], $callback['headers']));

        return response()->json(['outcome' => $processor->process($gateway->name(), $event)]);
    }
}
