<?php

namespace App\Console\Commands;

use App\Domain\Orders\Models\Order;
use App\Domain\Payments\Services\CashCollection;
use Illuminate\Console\Command;

class CollectCash extends Command
{
    protected $signature = 'orders:collect-cash {number} {amount : Exact amount in halalas} {reference : Unique cash receipt reference}';

    protected $description = 'Record a verified cash receipt for a fully delivered cash order';

    public function handle(CashCollection $collection): int
    {
        $amount = (string) $this->argument('amount');
        $reference = trim((string) $this->argument('reference'));
        if (! ctype_digit($amount) || strlen($amount) > 9 || ! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $reference)) {
            $this->error('Use integer halalas and an 8-64 character receipt reference.');

            return self::FAILURE;
        }
        $order = Order::query()->where('number', $this->argument('number'))->firstOrFail();
        $collection->record($order, (int) $amount, $reference);
        $this->info('Cash receipt recorded.');

        return self::SUCCESS;
    }
}
