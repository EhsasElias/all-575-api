<?php

namespace App\Console\Commands;

use App\Domain\Orders\Services\DeliverySlots;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('slots:generate')]
#[Description('Create the delivery windows for the coming days in every active zone')]
class GenerateDeliverySlots extends Command
{
    public function handle(DeliverySlots $slots): int
    {
        $this->info("Created {$slots->generate()} delivery slots.");

        return self::SUCCESS;
    }
}
