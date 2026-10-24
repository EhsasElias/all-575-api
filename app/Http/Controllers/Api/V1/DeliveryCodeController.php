<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\Services\DeliveryCodes;
use App\Http\Controllers\Controller;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryCodeController extends Controller
{
    /** The customer's own hand-over code, available only while that delivery is on its way. */
    public function show(Request $request, string $order, string $suborder, DeliveryCodes $codes): JsonResponse
    {
        $delivery = $request->user()->orders()->findOrFail($order)->suborders()->findOrFail($suborder);
        $code = $codes->visibleTo($delivery);

        if ($code === null) {
            throw new DomainRuleViolation('delivery_code_unavailable', 'There is no active hand-over code for this delivery.', 404);
        }

        return response()->json(['data' => ['code' => $code->code_encrypted, 'expires_at' => $code->expires_at]]);
    }
}
