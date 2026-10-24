<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\StockReservation;
use App\Support\Exceptions\DomainRuleViolation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Holds stock for an unpaid order. Every change is a single conditional UPDATE, so two buyers racing for the
 * last unit cannot both win, on any database, without relying on row locks.
 */
final class StockReservations
{
    /**
     * Holds each variant at the zone's branch with the most free stock. A line is never split across branches.
     *
     * @param  array<int, int>  $quantities  variant id => units
     * @return array<int, int> variant id => branch id that will fulfil it
     */
    public function hold(Order $order, array $quantities, int $zoneId, CarbonInterface $expiresAt): array
    {
        $branches = [];

        foreach ($quantities as $variantId => $quantity) {
            $branches[$variantId] = $this->holdOne($order, $variantId, $quantity, $zoneId, $expiresAt);
        }

        return $branches;
    }

    /** Payment arrived: the held units leave the shelf for good. */
    public function commit(Order $order): void
    {
        foreach ($order->reservations()->where('status', StockReservation::HELD)->get() as $reservation) {
            DB::table('branch_stock')->where('id', $reservation->branch_stock_id)
                ->whereRaw('reserved >= ? AND on_hand >= ?', [$reservation->quantity, $reservation->quantity])
                ->update([
                    'reserved' => DB::raw('reserved - '.(int) $reservation->quantity),
                    'on_hand' => DB::raw('on_hand - '.(int) $reservation->quantity),
                ]);

            $reservation->forceFill(['status' => StockReservation::COMMITTED])->save();
        }
    }

    /** The order will not be paid: the held units go back on sale. */
    public function release(Order $order): void
    {
        foreach ($order->reservations()->where('status', StockReservation::HELD)->get() as $reservation) {
            DB::table('branch_stock')->where('id', $reservation->branch_stock_id)
                ->where('reserved', '>=', $reservation->quantity)
                ->decrement('reserved', $reservation->quantity);

            $reservation->forceFill(['status' => StockReservation::RELEASED])->save();
        }
    }

    private function holdOne(Order $order, int $variantId, int $quantity, int $zoneId, CarbonInterface $expiresAt): int
    {
        $candidates = BranchStock::query()
            ->select('branch_stock.*')
            ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
            ->where('branches.service_zone_id', $zoneId)
            ->where('branches.is_active', true)
            ->where('branch_stock.variant_id', $variantId)
            ->orderByRaw('(branch_stock.on_hand - branch_stock.reserved) desc')
            ->orderBy('branch_stock.id')
            ->get();

        foreach ($candidates as $stock) {
            $held = DB::table('branch_stock')->where('id', $stock->id)
                ->whereRaw('on_hand - reserved >= ?', [$quantity])
                ->increment('reserved', $quantity);

            if ($held === 1) {
                StockReservation::query()->create([
                    'order_id' => $order->id,
                    'branch_stock_id' => $stock->id,
                    'quantity' => $quantity,
                    'status' => StockReservation::HELD,
                    'expires_at' => $expiresAt,
                ]);

                return $stock->branch_id;
            }
        }

        throw new DomainRuleViolation('out_of_stock', 'An item in your cart just sold out.', 409, ['variant_id' => $variantId]);
    }
}
