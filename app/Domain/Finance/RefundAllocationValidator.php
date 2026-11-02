<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates seller ownership and refund totals before finance approval.
 * Must run inside a transaction holding a lock on the refund row.
 */
final class RefundAllocationValidator
{
    public function validate(string $refundId, array $allocations): void
    {
        $refund = DB::table('refunds')->where('id', $refundId)->lockForUpdate()->first();

        if ($refund === null || $refund->status !== 'succeeded') {
            throw ValidationException::withMessages(['refund_id' => 'Refund must be completed.']);
        }

        $previous = DB::table('seller_refund_allocations')
            ->where('refund_id', $refundId)->sum('amount_halalas');
        $remaining = (int) $refund->amount_halalas - (int) $previous;
        $seen = [];

        foreach ($allocations as $allocation) {
            $suborderId = $allocation['suborder_id'];
            $amount = $allocation['amount_halalas'];

            if (! is_int($amount) || $amount <= 0 || isset($seen[$suborderId]) || $amount > $remaining) {
                throw ValidationException::withMessages(['allocations' => 'Duplicate or excessive refund allocation.']);
            }

            $linked = DB::table('suborders')
                ->where('id', $suborderId)
                ->where('order_id', $refund->order_id)
                ->where('seller_id', $allocation['seller_id'])
                ->exists();

            if (! $linked || DB::table('seller_refund_allocations')
                ->where('refund_id', $refundId)->where('suborder_id', $suborderId)->exists()) {
                throw ValidationException::withMessages(['allocations' => 'Seller or suborder does not match refund.']);
            }

            $seen[$suborderId] = true;
            $remaining -= $amount;
        }
    }
}
