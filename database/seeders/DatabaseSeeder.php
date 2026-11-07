<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isProduction()) {
            $this->call(DemoCatalogSeeder::class);
            if (trim((string) config('demo.seller_email', '')) !== '') {
                $this->call(DemoSellerAccountSeeder::class);
            }
            $this->call(SellerEmployeeNumberSeeder::class);
        }
    }
}
