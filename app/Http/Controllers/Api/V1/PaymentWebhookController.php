<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\Contracts\PaymentGateway;
use App\Domain\Payments\Gateways\InvalidWebhook;
use App\Domain\Payments\Services\PaymentProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    /** Called by the payment provider, not by the app. No login: the signature is the proof. */
    public function handle(Request $request, string $provider, PaymentGateway $gateway, PaymentProcessor $processor): JsonResponse
    {
        abort_unless($provider === $gateway->name(), 404);

        try {
            $event = $gateway->parseWebhook($request->getContent(), $request->headers->all());
        } catch (InvalidWebhook) {
            return response()->json(['message' => 'Invalid callback.'], 400);
        }

        $outcome = $processor->process($provider, $event);

        // A non-2xx answer makes the provider send the callback again, which is what we want for a reference we do not know yet.
        return response()->json(['outcome' => $outcome], PaymentProcessor::shouldRetryLater($outcome) ? 409 : 200);
    }
}
