<?php

namespace App\Http\Resources;

use App\Domain\Payments\Gateways\ChargeSession;
use App\Domain\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    public function __construct(Payment $resource, private readonly ?ChargeSession $session = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{id: string, order_id: string, status: 'pending'|'paid'|'failed'|'expired', method: 'mada'|'card'|'apple_pay'|'cash_on_delivery'|'none', amount: int, currency: string, expires_at: string, paid_at: ?string, failure_code: ?string, action: ?array{redirect_url: ?string, client_secret: ?string}}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'status' => $this->status,
            'method' => $this->method,
            'amount' => $this->amount_halalas,
            'currency' => $this->currency,
            'expires_at' => $this->expires_at,
            'paid_at' => $this->paid_at,
            'failure_code' => $this->failure_code,
            // Only present on the response that creates the charge; the provider session is not stored.
            'action' => $this->session === null ? null : [
                'redirect_url' => $this->session->redirectUrl,
                'client_secret' => $this->session->clientSecret,
            ],
        ];
    }
}
