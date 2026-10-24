<?php

namespace App\Console\Commands;

use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Services\SuborderTransitions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Stand-in for the seller and courier apps while they do not exist yet. Local and testing only. */
#[Signature('orders:advance {number : The order number, e.g. A575-ABCD1234} {status : preparing|ready|on_the_way|delivered}')]
#[Description('Move every delivery of an order to its next status (development only)')]
class AdvanceOrder extends Command
{
    public function handle(SuborderTransitions $transitions): int
    {
        if (app()->isProduction()) {
            $this->error('orders:advance is not available in production.');

            return self::FAILURE;
        }

        $next = SuborderStatus::tryFrom((string) $this->argument('status'));
        $order = Order::query()->where('number', $this->argument('number'))->with('suborders')->first();

        if ($next === null || $order === null) {
            $this->error('Unknown order number or status.');

            return self::FAILURE;
        }

        foreach ($order->suborders as $suborder) {
            $transitions->move($suborder, $next, 'admin');
            $this->info("{$suborder->id} -> {$next->value}");
        }

        return self::SUCCESS;
    }
}
