<?php

declare(strict_types=1);

namespace App\Domain\Orders\Events;

use App\Domain\Orders\Models\Order;

/** Fired once, after payment is confirmed and the order is committed. Listeners (push, email) hang off this. */
final class OrderPaid
{
    public function __construct(public readonly Order $order) {}
}
