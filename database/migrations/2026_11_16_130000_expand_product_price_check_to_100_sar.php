<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The original MySQL migration created products_chk_1 with an upper bound of 575.
        // Updating Eloquent validation alone does not remove that database check.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE products DROP CHECK products_chk_1, '.
            'ADD CONSTRAINT products_price_halalas_range_chk '.
            'CHECK (price_halalas BETWEEN 100 AND 10000)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Rolling back requires first reducing any prices above 575 halalas.
        DB::statement(
            'ALTER TABLE products DROP CHECK products_price_halalas_range_chk, '.
            'ADD CONSTRAINT products_chk_1 '.
            'CHECK (price_halalas BETWEEN 100 AND 575)'
        );
    }
};
