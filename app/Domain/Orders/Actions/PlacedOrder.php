<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Gateways\ChargeSession;
use App\Domain\Payments\Models\Payment;

final readonly class PlacedOrder
{
    public function __construct(
        public Order $order,
        public ?Payment $payment,
        public ?ChargeSession $session,
        public bool $replayed,
    ) {}
}
