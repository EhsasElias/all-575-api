<?php

namespace App\Http\Support;

use App\Domain\Orders\Models\Order;

/** Loads everything OrderDetailResource reads, in one place, so no endpoint triggers lazy loading. */
class OrderPresenter
{
    public function load(Order $order): Order
    {
        return $order->load(['items', 'payments', 'refunds', 'events', 'suborders.seller', 'suborders.items']);
    }
}
