<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products ADD COLUMN price_halalas INTEGER NOT NULL DEFAULT 575 CHECK (price_halalas BETWEEN 100 AND 575)');
        Schema::table('products', fn (Blueprint $table) => $table->index('price_halalas'));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('price_halalas'));
    }
};
