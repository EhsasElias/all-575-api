<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Refund;
use Carbon\CarbonInterface;

/** A customer's own spending. Order date, payment date and refund date are kept apart on every row. */
final class SpendingReport
{
    /**
     * @return array{summary: array<string, int>, rows: list<array<string, mixed>>}
     */
    public function for(User $user, CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            ->whereNotNull('paid_at')
            ->whereBetween('placed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with(['refunds' => fn ($query) => $query->where('status', RefundStatus::Succeeded->value)])
            ->orderBy('placed_at')
            ->orderBy('id')
            ->get();

        $rows = $orders->map(fn (Order $order): array => $this->row($order))->values()->all();

        return ['summary' => $this->summarise($rows), 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function row(Order $order): array
    {
        $refunded = (int) $order->refunds->sum('amount_halalas');

        return [
            'order_number' => $order->number,
            'status' => $order->status->value,
            'order_date' => $order->placed_at,
            'payment_date' => $order->paid_at,
            'refund_date' => $order->refunds->max('completed_at'),
            'subtotal' => $order->subtotal_halalas,
            'discount' => $order->discount_halalas,
            'delivery_fee' => $order->delivery_fee_halalas,
            'vat_included' => $order->vat_halalas,
            'total' => $order->total_halalas,
            'refunded' => $refunded,
            'net_spent' => $order->total_halalas - $refunded,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summarise(array $rows): array
    {
        $sum = fn (string $key): int => (int) array_sum(array_column($rows, $key));

        return [
            'orders' => count($rows),
            'paid' => $sum('total'),
            'refunded' => $sum('refunded'),
            'net_spent' => $sum('net_spent'),
            'savings' => $sum('discount'),
            'vat_included' => $sum('vat_included'),
            'delivery_fees' => $sum('delivery_fee'),
        ];
    }
}
