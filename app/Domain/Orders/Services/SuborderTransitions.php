<?php

declare(strict_types=1);

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Enums\OrderStatus;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\Suborder;
use App\Support\Exceptions\DomainRuleViolation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The single door for moving a delivery along. Seller, courier and admin apps will call this; for now the
 * `orders:advance` command does. Only the allowed next steps pass, and each step is written to the timeline.
 */
final class SuborderTransitions
{
    public function __construct(private readonly DeliveryCodes $codes) {}

    public function move(Suborder $suborder, SuborderStatus $next, string $actor): Suborder
    {
        return DB::transaction(function () use ($suborder, $next, $actor): Suborder {
            $locked = Suborder::query()->lockForUpdate()->findOrFail($suborder->id);

            if (! $locked->status->canMoveTo($next)) {
                throw new DomainRuleViolation('invalid_transition', "A delivery cannot go from {$locked->status->value} to {$next->value}.", 409);
            }

            $locked->forceFill(['status' => $next] + $this->timestampFor($next))->save();

            if ($next === SuborderStatus::OnTheWay) {
                $this->codes->issue($locked);
            }

            $locked->order->record($next->value, $actor, null, [], $locked);
            $this->syncOrder($locked);

            return $locked;
        });
    }

    public function reviseEta(Suborder $suborder, CarbonInterface $eta, string $reason, string $actor): void
    {
        $suborder->forceFill(['eta_at' => $eta, 'eta_reason' => $reason])->save();
        $suborder->order->record('eta_revised', $actor, $reason, ['eta' => $eta->toIso8601String()], $suborder);
    }

    /** @return array<string, CarbonInterface> */
    private function timestampFor(SuborderStatus $status): array
    {
        return match ($status) {
            SuborderStatus::Preparing => ['preparing_at' => now()],
            SuborderStatus::Ready => ['ready_at' => now()],
            SuborderStatus::OnTheWay => ['picked_up_at' => now()],
            SuborderStatus::Delivered => ['delivered_at' => now()],
            default => [],
        };
    }

    /** The order is complete when every delivery is delivered or cancelled and at least one was delivered. */
    private function syncOrder(Suborder $suborder): void
    {
        $order = $suborder->order()->lockForUpdate()->firstOrFail();
        $statuses = $order->suborders()->pluck('status');
        $open = $statuses->contains(fn (SuborderStatus $s): bool => ! in_array($s, [SuborderStatus::Delivered, SuborderStatus::Cancelled], true));

        if ($open || $order->status !== OrderStatus::Confirmed) {
            return;
        }

        $order->forceFill(['status' => $statuses->contains(SuborderStatus::Delivered) ? OrderStatus::Completed : OrderStatus::Cancelled])->save();
        $order->record($order->status === OrderStatus::Completed ? 'order_completed' : 'order_cancelled', 'system');
    }
}
