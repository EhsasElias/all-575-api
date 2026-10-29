<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerDashboardResource extends JsonResource
{
    /**
     * All-time totals in integer halalas. Only verified paid amounts and succeeded refunds affect spending.
     *
     * @return array{orders_total: int, orders_active: int, orders_awaiting_payment: int, orders_completed: int, paid_total: int, refunded_total: int, net_spent: int, currency: string, generated_at: string, saved_products: int, addresses: int, open_support: int, open_requests: int, recent_orders: list<OrderResource>}
     */
    public function toArray(Request $request): array
    {
        return [
            'orders_total' => (int) $this->resource['orders_total'],
            'orders_active' => (int) $this->resource['orders_active'],
            'orders_awaiting_payment' => (int) $this->resource['orders_awaiting_payment'],
            'orders_completed' => (int) $this->resource['orders_completed'],
            'paid_total' => (int) $this->resource['paid_total'],
            'refunded_total' => (int) $this->resource['refunded_total'],
            'net_spent' => (int) $this->resource['net_spent'],
            'saved_products' => (int) $this->resource['saved_products'],
            'addresses' => (int) $this->resource['addresses'],
            'open_support' => (int) $this->resource['open_support'],
            'open_requests' => (int) $this->resource['open_requests'],
            'currency' => (string) $this->resource['currency'],
            'generated_at' => (string) $this->resource['generated_at'],
            'recent_orders' => $this->recentOrders(),
        ];
    }

    /** @return list<OrderResource> */
    private function recentOrders(): array
    {
        $orders = [];
        foreach ($this->resource['recent_orders'] as $order) {
            $orders[] = new OrderResource($order);
        }

        return $orders;
    }
}
