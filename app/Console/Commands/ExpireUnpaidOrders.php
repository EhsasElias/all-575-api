<?php

namespace App\Console\Commands;

use App\Domain\Orders\Services\UnpaidOrderCancellation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:expire-unpaid')]
#[Description('Cancel unpaid orders whose held stock has timed out and give the stock and slot back')]
class ExpireUnpaidOrders extends Command
{
    public function handle(UnpaidOrderCancellation $cancellation): int
    {
        $this->info("Cancelled {$cancellation->expireOverdue()} unpaid orders.");

        return self::SUCCESS;
    }
}
