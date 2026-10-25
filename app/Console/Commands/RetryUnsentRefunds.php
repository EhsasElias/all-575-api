<?php

namespace App\Console\Commands;

use App\Domain\Payments\Services\Refunds;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:retry-refunds')]
#[Description('Send the refunds that were recorded but never reached the payment provider')]
class RetryUnsentRefunds extends Command
{
    public function handle(Refunds $refunds): int
    {
        $this->info("Tried {$refunds->retryUnsent()} unsent refunds.");

        return self::SUCCESS;
    }
}
