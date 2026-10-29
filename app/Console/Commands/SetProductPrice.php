<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use Illuminate\Console\Command;

class SetProductPrice extends Command
{
    protected $signature = 'catalog:set-price {slug} {halalas}';

    protected $description = 'Set a product price between 100 and 575 halalas (VAT included)';

    public function handle(): int
    {
        $value = (string) $this->argument('halalas');
        if (! ctype_digit($value) || (int) $value < 100 || (int) $value > 575) {
            $this->error('Use integer halalas from 100 to 575.');

            return self::FAILURE;
        }
        $product = Product::query()->where('slug', $this->argument('slug'))->first();
        if ($product === null) {
            $this->error('Product not found.');

            return self::FAILURE;
        }
        $product->update(['price_halalas' => (int) $value]);
        $this->info('Product price updated. Existing orders keep their original prices.');

        return self::SUCCESS;
    }
}
